<?php

namespace App\Services\Subscriptions;

use App\Jobs\SendMembershipBookingConfirmation;
use App\Models\ArInvoice;
use App\Models\Branch;
use App\Models\MealSubscription;
use App\Models\MealSubscriptionOrder;
use App\Models\MembershipBookingFunding;
use App\Models\MembershipBookingOperation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\AR\ArInvoiceService;
use App\Services\Customers\CustomerOwnershipService;
use App\Services\Orders\OrderNumberService;
use App\Services\Payments\CheckoutCanonicalizer;
use App\Services\Payments\PaymentCheckoutException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipBookingService
{
    public function __construct(
        private readonly MembershipBookingQuoteService $quotes,
        private readonly MembershipQueueService $queues,
        private readonly MembershipBookingFundingService $funding,
        private readonly CustomerOwnershipService $customerOwnership,
        private readonly OrderNumberService $numbers,
        private readonly ArInvoiceService $invoices,
        private readonly CheckoutCanonicalizer $canonicalizer,
        private readonly AccountingAuditLogService $auditLog,
        private readonly AccountingContextService $accountingContext,
    ) {}

    /** @param array<string, mixed> $request
     * @return array{result:array<string,mixed>,replayed:bool,status:int}
     */
    public function create(User $user, array $request): array
    {
        $clientUuid = strtolower(trim((string) ($request['client_uuid'] ?? '')));
        if (! Str::isUuid($clientUuid)) {
            throw ValidationException::withMessages(['client_uuid' => __('A valid booking identifier is required.')]);
        }
        $requestFingerprint = $this->requestFingerprint($request);
        $replay = $this->findReplay($user, $clientUuid);
        if ($replay) {
            $this->assertReplayMatches($replay, $requestFingerprint);

            return ['result' => $replay->result_snapshot, 'replayed' => true, 'status' => 200];
        }

        $quote = $this->quotes->quote($user, $request);
        $this->assertAcceptedQuote($quote, $request);

        return DB::transaction(function () use ($user, $request, $clientUuid, $requestFingerprint): array {
            $customer = $this->customerOwnership->lockCanonicalCustomer((int) $user->customer_id);
            if (! $customer->isActive()) {
                throw ValidationException::withMessages(['account' => __('This customer account is not available.')]);
            }

            $replay = MembershipBookingOperation::query()
                ->whereIn('customer_id', $this->customerOwnership->historicalCustomerIds($customer->id))
                ->where('client_uuid', $clientUuid)
                ->lockForUpdate()
                ->first();
            if ($replay) {
                $this->assertReplayMatches($replay, $requestFingerprint);

                return ['result' => $replay->result_snapshot, 'replayed' => true, 'status' => 200];
            }

            $quote = $this->quotes->quote($user, $request);
            $this->assertAcceptedQuote($quote, $request);
            $context = $quote['_context'];
            $roots = $this->queues->lockCompatibleRoots(
                $customer->id,
                (int) $context['company_id'],
                (int) $context['branch']->id,
            );
            $root = $roots->sortBy([['created_at', 'asc'], ['id', 'asc']])->first();
            if (! $root || ! hash_equals((string) $root->subscription_code, (string) $quote['queue_reference'])) {
                throw new PaymentCheckoutException('MEMBERSHIP_SCOPE_CHANGED', 409, __('Your membership changed. Review the current balance before booking.'));
            }
            if ((int) $quote['queue_revision'] !== (int) ($request['queue_revision'] ?? -1)) {
                throw new PaymentCheckoutException(
                    'MEMBERSHIP_QUEUE_CHANGED',
                    409,
                    __('Your membership balance changed. Review it before booking.'),
                    ['queue' => $quote['queue']],
                );
            }

            $actorId = (int) config('payments.system_user_id');
            if ($actorId <= 0) {
                throw new PaymentCheckoutException('SYSTEM_ACTOR_MISSING', 503, __('The booking system actor is unavailable.'));
            }
            $issueDate = now('Asia/Qatar')->toDateString();
            $cutoff = (string) $context['settings']->booking_cutoff_time;
            $bookings = [];

            foreach ($quote['_priced_days'] as $day) {
                $bookings[] = $this->createBookedDay(
                    user: $user,
                    customerId: $customer->id,
                    branchId: (int) $context['branch']->id,
                    root: $root,
                    roots: $roots,
                    day: $day,
                    operationUuid: $clientUuid,
                    issueDate: $issueDate,
                    actorId: $actorId,
                    cutoff: $cutoff,
                );
            }

            $queue = $this->queues->summary(
                $customer->id,
                (int) $context['company_id'],
                (int) $context['branch']->id,
            );
            $result = [
                'result_kind' => 'covered_booking',
                'operation_reference' => $clientUuid,
                'payable_amount_cents' => 0,
                'currency' => 'QAR',
                'main_quantity' => (int) $quote['main_quantity'],
                'bookings' => $bookings,
                'queue' => $queue,
                'message' => __('Your meals are booked from your paid membership. No payment is required.'),
            ];
            $operation = MembershipBookingOperation::query()->create([
                'client_uuid' => $clientUuid,
                'portal_user_id' => $user->id,
                'customer_id' => $customer->id,
                'company_id' => $context['company_id'],
                'branch_id' => $context['branch']->id,
                'subscription_id' => $root->id,
                'input_queue_revision' => (int) $request['queue_revision'],
                'request_fingerprint' => $requestFingerprint,
                'state' => 'completed',
                'request_snapshot' => [
                    'queue_reference' => $quote['queue_reference'],
                    'selections' => $quote['selections'],
                    'quote_fingerprint' => $quote['quote_fingerprint'],
                ],
                'terms_snapshot' => [
                    'version' => $quote['terms_version'],
                    'url' => $quote['terms_url'],
                    'content_hash' => $quote['terms_content_hash'],
                ],
                'result_snapshot' => $result,
                'completed_at' => now('UTC'),
            ]);
            $this->auditLog->log('membership.booking.created', $actorId, $operation, [
                'membership_booking_operation_id' => $operation->id,
                'subscription_id' => $root->id,
                'booking_references' => array_column($bookings, 'booking_reference'),
                'order_ids' => array_column($bookings, 'order_id'),
                'invoice_ids' => array_column($bookings, 'invoice_id'),
                'main_quantity' => (int) $quote['main_quantity'],
                'input_queue_revision' => (int) $request['queue_revision'],
                'result_queue_revision' => (int) $queue['queue_revision'],
            ], (int) $context['company_id']);

            return ['result' => $result, 'replayed' => false, 'status' => 201];
        }, 3);
    }

    /** @param array<string, mixed> $request
     * @return array{result:array<string,mixed>,replayed:bool,status:int}
     */
    public function replace(User $user, string $bookingReference, array $request): array
    {
        $clientUuid = $this->validatedClientUuid($request);
        $request['booking_reference'] = $bookingReference;
        $request['booking_revision'] = (int) ($request['expected_booking_revision'] ?? 0);
        unset($request['expected_booking_revision']);
        $requestFingerprint = $this->mutationFingerprint('replace', $request);
        if ($replay = $this->findReplay($user, $clientUuid)) {
            $this->assertReplayMatches($replay, $requestFingerprint);

            return ['result' => $replay->result_snapshot, 'replayed' => true, 'status' => 200];
        }

        $quote = $this->quotes->quote($user, $request);
        $this->assertAcceptedQuote($quote, $request);

        return DB::transaction(function () use ($user, $request, $bookingReference, $clientUuid, $requestFingerprint): array {
            $customer = $this->customerOwnership->lockCanonicalCustomer((int) $user->customer_id);
            if ($replay = $this->lockedReplay($customer->id, $clientUuid)) {
                $this->assertReplayMatches($replay, $requestFingerprint);

                return ['result' => $replay->result_snapshot, 'replayed' => true, 'status' => 200];
            }

            $quote = $this->quotes->quote($user, $request);
            $this->assertAcceptedQuote($quote, $request);
            $context = $quote['_context'];
            $roots = $this->queues->lockCompatibleRoots(
                $customer->id,
                (int) $context['company_id'],
                (int) $context['branch']->id,
            );
            $root = $this->assertQueueRoot($roots, (string) $quote['queue_reference']);
            $expectedRevision = (int) $request['booking_revision'];
            $mapping = $this->lockActiveBooking($roots, $bookingReference, $expectedRevision);
            $this->assertBeforeDeadline($mapping);
            $replacement = $quote['_replacement'];
            if ((int) $replacement['mapping']->id !== (int) $mapping->id) {
                throw new PaymentCheckoutException('MEMBERSHIP_BOOKING_CHANGED', 409, __('This membership booking changed. Reload it before continuing.'));
            }

            $oldOrder = $mapping->order;
            $oldInvoice = ArInvoice::query()->where('source_order_id', $oldOrder->id)->firstOrFail();
            $actorId = $this->systemActorId();
            $this->invoices->void($oldInvoice, $actorId, __('Customer changed membership booking.'));
            $profileSnapshot = [
                'name' => $oldOrder->customer_name_snapshot,
                'phone' => $oldOrder->customer_phone_snapshot,
                'email' => $oldOrder->customer_email_snapshot,
                'address' => $oldOrder->delivery_address_snapshot,
            ];
            $booking = $this->createBookedDay(
                user: $user,
                customerId: $customer->id,
                branchId: (int) $context['branch']->id,
                root: $root,
                roots: $roots,
                day: $quote['_priced_days'][0],
                operationUuid: $clientUuid,
                issueDate: now('Asia/Qatar')->toDateString(),
                actorId: $actorId,
                cutoff: (string) $replacement['booking_cutoff_time'],
                bookingUuid: $bookingReference,
                bookingRevision: $expectedRevision + 1,
                supersedes: $mapping,
                deadline: $replacement['new_change_deadline'],
                profileSnapshot: $profileSnapshot,
                notificationKind: 'changed',
            );
            $queue = $this->queues->summary($customer->id, (int) $context['company_id'], (int) $context['branch']->id);
            $result = [
                'result_kind' => 'covered_booking_replaced',
                'operation_reference' => $clientUuid,
                'payable_amount_cents' => 0,
                'currency' => 'QAR',
                'booking' => $booking,
                'replaced_booking_revision' => $expectedRevision,
                'queue' => $queue,
                'message' => __('Your membership booking was updated. No payment is required.'),
            ];
            $operation = $this->recordOperation(
                user: $user,
                customerId: $customer->id,
                companyId: (int) $context['company_id'],
                branchId: (int) $context['branch']->id,
                root: $root,
                clientUuid: $clientUuid,
                inputQueueRevision: (int) $request['queue_revision'],
                requestFingerprint: $requestFingerprint,
                requestSnapshot: [
                    'action' => 'replace',
                    'booking_reference' => $bookingReference,
                    'booking_revision' => $expectedRevision,
                    'selections' => $quote['selections'],
                    'quote_fingerprint' => $quote['quote_fingerprint'],
                ],
                termsSnapshot: [
                    'version' => $quote['terms_version'],
                    'url' => $quote['terms_url'],
                    'content_hash' => $quote['terms_content_hash'],
                ],
                result: $result,
            );
            $this->auditLog->log('membership.booking.replaced', $actorId, $operation, [
                'booking_reference' => $bookingReference,
                'old_booking_revision' => $expectedRevision,
                'new_booking_revision' => $booking['booking_revision'],
                'old_order_id' => (int) $oldOrder->id,
                'new_order_id' => $booking['order_id'],
                'old_invoice_id' => (int) $oldInvoice->id,
                'new_invoice_id' => $booking['invoice_id'],
            ], (int) $context['company_id']);

            return ['result' => $result, 'replayed' => false, 'status' => 200];
        }, 3);
    }

    /** @param array<string, mixed> $request
     * @return array{result:array<string,mixed>,replayed:bool,status:int}
     */
    public function cancel(User $user, string $bookingReference, array $request): array
    {
        $clientUuid = $this->validatedClientUuid($request);
        $requestFingerprint = $this->mutationFingerprint('cancel', [
            ...$request,
            'booking_reference' => $bookingReference,
        ]);
        if ($replay = $this->findReplay($user, $clientUuid)) {
            $this->assertReplayMatches($replay, $requestFingerprint);

            return ['result' => $replay->result_snapshot, 'replayed' => true, 'status' => 200];
        }

        return DB::transaction(function () use ($user, $request, $bookingReference, $clientUuid, $requestFingerprint): array {
            $customer = $this->customerOwnership->lockCanonicalCustomer((int) $user->customer_id);
            if ($replay = $this->lockedReplay($customer->id, $clientUuid)) {
                $this->assertReplayMatches($replay, $requestFingerprint);

                return ['result' => $replay->result_snapshot, 'replayed' => true, 'status' => 200];
            }

            $companyId = (int) $this->accountingContext->defaultCompanyId();
            $branchId = (int) config('payments.public_order_branch_id', 1);
            $branch = Branch::query()->find($branchId);
            if ($companyId <= 0 || (int) ($request['selected_branch_id'] ?? 0) !== $branchId
                || ! $branch?->is_active || (int) $branch->company_id !== $companyId) {
                throw ValidationException::withMessages(['selected_branch_id' => __('This membership branch is unavailable.')]);
            }
            $roots = $this->queues->lockCompatibleRoots($customer->id, $companyId, $branchId);
            $root = $this->assertQueueRoot($roots, trim((string) ($request['queue_reference'] ?? '')));
            $queueBefore = $this->queues->summary($customer->id, $companyId, $branchId);
            if ((int) ($request['queue_revision'] ?? -1) !== (int) $queueBefore['queue_revision']) {
                throw new PaymentCheckoutException(
                    'MEMBERSHIP_QUEUE_CHANGED',
                    409,
                    __('Your membership balance changed. Reload it before cancelling.'),
                    ['queue' => $queueBefore],
                );
            }
            $expectedRevision = (int) ($request['expected_booking_revision'] ?? 0);
            $mapping = $this->lockActiveBooking($roots, $bookingReference, $expectedRevision);
            $this->assertBeforeDeadline($mapping);
            $invoice = ArInvoice::query()->where('source_order_id', $mapping->order_id)->firstOrFail();
            $actorId = $this->systemActorId();
            $releasedQuantity = (int) $mapping->funding->whereIn('state', ['reserved', 'invoiced'])->sum('main_quantity');
            $this->invoices->void($invoice, $actorId, __('Customer cancelled membership booking.'));
            $snapshot = is_array($mapping->notification_snapshots) ? $mapping->notification_snapshots : [];
            $snapshot['cancelled_at'] = now()->toIso8601String();
            $dispatch = is_array($mapping->notification_dispatch) ? $mapping->notification_dispatch : [];
            $dispatch['customer_cancellation'] = ['state' => 'pending'];
            $mapping->update([
                'notification_snapshots' => $snapshot,
                'notification_dispatch' => $dispatch,
            ]);
            DB::afterCommit(fn () => SendMembershipBookingConfirmation::dispatch((int) $mapping->id, 'cancelled'));
            $queue = $this->queues->summary($customer->id, $companyId, $branchId);
            $result = [
                'result_kind' => 'covered_booking_cancelled',
                'operation_reference' => $clientUuid,
                'booking_reference' => $bookingReference,
                'booking_revision' => $expectedRevision,
                'released_main_quantity' => $releasedQuantity,
                'queue' => $queue,
                'message' => __('Your membership booking was cancelled and the meals are available again.'),
            ];
            $operation = $this->recordOperation(
                user: $user,
                customerId: $customer->id,
                companyId: $companyId,
                branchId: $branchId,
                root: $root,
                clientUuid: $clientUuid,
                inputQueueRevision: (int) $request['queue_revision'],
                requestFingerprint: $requestFingerprint,
                requestSnapshot: [
                    'action' => 'cancel',
                    'booking_reference' => $bookingReference,
                    'booking_revision' => $expectedRevision,
                ],
                termsSnapshot: [],
                result: $result,
            );
            $this->auditLog->log('membership.booking.cancelled', $actorId, $operation, [
                'booking_reference' => $bookingReference,
                'booking_revision' => $expectedRevision,
                'order_id' => (int) $mapping->order_id,
                'invoice_id' => (int) $invoice->id,
                'released_main_quantity' => $releasedQuantity,
            ], $companyId);

            return ['result' => $result, 'replayed' => false, 'status' => 200];
        }, 3);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\MealSubscription>  $roots
     * @param  array<string, mixed>  $day
     * @param  array<string, string|null>|null  $profileSnapshot
     * @return array<string, mixed>
     */
    private function createBookedDay(
        User $user,
        int $customerId,
        int $branchId,
        \App\Models\MealSubscription $root,
        \Illuminate\Support\Collection $roots,
        array $day,
        string $operationUuid,
        string $issueDate,
        int $actorId,
        string $cutoff,
        ?string $bookingUuid = null,
        int $bookingRevision = 1,
        ?MealSubscriptionOrder $supersedes = null,
        ?CarbonImmutable $deadline = null,
        ?array $profileSnapshot = null,
        string $notificationKind = 'created',
    ): array {
        $quantity = collect($day['submission']['mains'])->sum(fn (array $main): int => (int) $main['qty']);
        $attributions = $this->funding->reserveOldestPositions($roots, $quantity);
        $bookingUuid ??= (string) Str::uuid();
        $deadline ??= CarbonImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $day['date'].' '.$cutoff,
            'Asia/Qatar',
        )->subDay();
        $order = $this->createOrder($user, $customerId, $branchId, $day, $attributions, $actorId, $profileSnapshot);
        $mapping = MealSubscriptionOrder::query()->create([
            'subscription_id' => $root->id,
            'order_id' => $order->id,
            'service_date' => $day['date'],
            'branch_id' => $branchId,
            'booking_uuid' => $bookingUuid,
            'booking_revision' => $bookingRevision,
            'supersedes_subscription_order_id' => $supersedes?->id,
            'accepted_operation_uuid' => $operationUuid,
            'notification_snapshots' => [
                'customer_email' => $profileSnapshot['email'] ?? $user->email,
                'booking_uuid' => $bookingUuid,
                'booking_revision' => $bookingRevision,
                'service_date' => $day['date'],
                'main_quantity' => $quantity,
                'change_deadline_at' => $deadline->toIso8601String(),
            ],
            'notification_dispatch' => [
                $notificationKind === 'changed' ? 'customer_change' : 'customer_creation' => ['state' => 'pending'],
            ],
        ]);

        $invoice = $this->createInvoice($order, $mapping, $attributions, $issueDate, $actorId);
        foreach ($attributions as $attribution) {
            MembershipBookingFunding::query()->create([
                'purchase_block_id' => $attribution['block']->id,
                'subscription_order_id' => $mapping->id,
                'main_quantity' => $attribution['main_quantity'],
                'position_ranges' => $attribution['position_ranges'],
                'invoice_id' => $invoice->id,
                'intended_invoice_issue_date' => $issueDate,
                'invoice_gross_cents' => $attribution['gross_cents'],
                'invoice_discount_cents' => $attribution['discount_cents'],
                'invoice_net_cents' => $attribution['net_cents'],
                'state' => 'reserved',
                'reserved_at' => now(),
                'booking_cutoff_time' => $cutoff,
                'booking_timezone' => 'Asia/Qatar',
                'change_deadline_at' => $deadline,
                'created_by' => $actorId,
            ]);
        }

        $invoice = $this->invoices->issue($invoice, $actorId, true)->fresh(['paymentAllocations']);
        if ($invoice->status !== 'paid'
            || (int) $invoice->balance_cents !== 0
            || (int) $invoice->paymentAllocations->sum('amount_cents') !== (int) $invoice->total_cents
            || MembershipBookingFunding::query()->where('subscription_order_id', $mapping->id)->where('state', 'invoiced')->count() !== count($attributions)) {
            throw new \RuntimeException('The membership booking invoice was not fully funded.');
        }
        DB::afterCommit(fn () => SendMembershipBookingConfirmation::dispatch((int) $mapping->id, $notificationKind));

        return [
            'booking_reference' => $bookingUuid,
            'booking_revision' => $bookingRevision,
            'service_date' => $day['date'],
            'main_quantity' => $quantity,
            'order_id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'change_deadline_at' => $deadline->toIso8601String(),
            'status' => 'scheduled',
        ];
    }

    /** @param array<string, mixed> $day
     * @param  array<int, array<string, mixed>>  $attributions
     */
    private function createOrder(
        User $user,
        int $customerId,
        int $branchId,
        array $day,
        array $attributions,
        int $actorId,
        ?array $profileSnapshot = null,
    ): Order {
        $total = (int) collect($attributions)->sum('net_cents');
        $customerSnapshot = $user->customer;
        $order = Order::query()->create([
            'order_number' => $this->numbers->generate(),
            'branch_id' => $branchId,
            'source' => 'Subscription',
            'is_daily_dish' => true,
            'daily_dish_portion_type' => null,
            'daily_dish_portion_quantity' => null,
            'type' => 'Delivery',
            'status' => 'Draft',
            'customer_id' => $customerId,
            'user_id' => $user->id,
            'customer_name_snapshot' => $profileSnapshot['name'] ?? $customerSnapshot?->name ?? $user->portal_name ?? $user->name,
            'customer_phone_snapshot' => $profileSnapshot['phone'] ?? $user->portal_phone_e164 ?? $customerSnapshot?->phone,
            'customer_email_snapshot' => $profileSnapshot['email'] ?? $user->email,
            'delivery_address_snapshot' => $profileSnapshot['address'] ?? $user->portal_delivery_address ?? $customerSnapshot?->delivery_address,
            'scheduled_date' => $day['date'],
            'scheduled_time' => null,
            'notes' => $day['notes'] ?? null,
            'order_discount_amount' => $this->decimalCents((int) collect($attributions)->sum('discount_cents')),
            'total_before_tax' => $this->decimalCents((int) collect($attributions)->sum('gross_cents')),
            'tax_amount' => '0.000',
            'total_amount' => $this->decimalCents($total),
            'created_by' => $actorId,
        ]);
        foreach (array_values($day['order_lines']) as $index => $line) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'menu_item_id' => (int) $line['menu_item_id'],
                'description_snapshot' => (string) $line['description'],
                'quantity' => (string) (int) $line['quantity'],
                'unit_price' => '0.000',
                'discount_amount' => '0.000',
                'line_total' => '0.000',
                'status' => 'Pending',
                'sort_order' => $index,
                'role' => (string) $line['role'],
            ]);
        }

        return $order;
    }

    /** @param array<int, array<string, mixed>> $attributions */
    private function createInvoice(
        Order $order,
        MealSubscriptionOrder $mapping,
        array $attributions,
        string $issueDate,
        int $actorId,
    ): ArInvoice {
        $items = array_map(function (array $attribution) use ($order, $mapping): array {
            $ranges = collect($attribution['position_ranges'])
                ->map(fn (array $range): string => $range[0] === $range[1] ? (string) $range[0] : $range[0].'-'.$range[1])
                ->implode(', ');

            return [
                'description' => __('Daily Dish membership meals for :date', ['date' => $order->scheduled_date?->format('Y-m-d')]),
                'qty' => '1.000',
                'unit_price_cents' => (int) $attribution['gross_cents'],
                'discount_cents' => (int) $attribution['discount_cents'],
                'tax_cents' => 0,
                'line_total_cents' => (int) $attribution['net_cents'],
                'meta' => [
                    'is_subscription' => true,
                    'subscription_id' => (int) $attribution['block']->subscription_id,
                    'subscription_order_id' => (int) $mapping->id,
                    'membership_purchase_block_id' => (int) $attribution['block']->id,
                    'main_quantity' => (int) $attribution['main_quantity'],
                    'position_ranges' => $attribution['position_ranges'],
                    'position_label' => $ranges,
                    'order_id' => (int) $order->id,
                ],
            ];
        }, $attributions);
        $invoice = $this->invoices->createDraft(
            branchId: (int) $order->branch_id,
            customerId: (int) $order->customer_id,
            items: $items,
            actorId: $actorId,
            currency: 'QAR',
            posReference: (string) $order->order_number,
            source: 'order',
            issueDate: $issueDate,
            paymentType: 'credit',
        );
        $invoice->update([
            'source_order_id' => $order->id,
            'notes' => __('Funded from paid membership allowance.'),
            'updated_by' => $actorId,
        ]);
        $order->update(['invoiced_at' => now('UTC')]);

        return $invoice->fresh(['items']);
    }

    private function findReplay(User $user, string $clientUuid): ?MembershipBookingOperation
    {
        return MembershipBookingOperation::query()
            ->whereIn('customer_id', $this->customerOwnership->historicalCustomerIds((int) $user->customer_id))
            ->where('client_uuid', $clientUuid)
            ->first();
    }

    private function lockedReplay(int $customerId, string $clientUuid): ?MembershipBookingOperation
    {
        return MembershipBookingOperation::query()
            ->whereIn('customer_id', $this->customerOwnership->historicalCustomerIds($customerId))
            ->where('client_uuid', $clientUuid)
            ->lockForUpdate()
            ->first();
    }

    /** @param Collection<int, MealSubscription> $roots */
    private function assertQueueRoot(Collection $roots, string $queueReference): MealSubscription
    {
        $root = $roots->sortBy([['created_at', 'asc'], ['id', 'asc']])->first();
        if (! $root || ! hash_equals((string) $root->subscription_code, $queueReference)) {
            throw new PaymentCheckoutException('MEMBERSHIP_SCOPE_CHANGED', 409, __('Your membership changed. Reload it before continuing.'));
        }

        return $root;
    }

    /** @param Collection<int, MealSubscription> $roots */
    private function lockActiveBooking(Collection $roots, string $bookingReference, int $expectedRevision): MealSubscriptionOrder
    {
        $candidate = MealSubscriptionOrder::query()
            ->with('funding')
            ->whereIn('subscription_id', $roots->pluck('id'))
            ->where('booking_uuid', $bookingReference)
            ->orderByDesc('booking_revision')
            ->first();
        if (! $candidate) {
            throw new PaymentCheckoutException('MEMBERSHIP_BOOKING_NOT_FOUND', 404, __('Membership booking was not found.'));
        }
        $invoiceIds = $candidate->funding
            ->whereIn('state', ['reserved', 'invoiced'])
            ->pluck('invoice_id')
            ->filter()
            ->unique();
        if ($invoiceIds->count() !== 1) {
            throw new PaymentCheckoutException('MEMBERSHIP_BOOKING_NOT_ACTIVE', 409, __('This membership booking is no longer active.'));
        }
        $this->funding->lockQueueForInvoiceMutation((int) $invoiceIds->first());

        $mapping = MealSubscriptionOrder::query()
            ->with(['order', 'funding'])
            ->whereIn('subscription_id', $roots->pluck('id'))
            ->where('booking_uuid', $bookingReference)
            ->orderByDesc('booking_revision')
            ->lockForUpdate()
            ->firstOrFail();
        if ((int) $mapping->booking_revision !== $expectedRevision) {
            throw new PaymentCheckoutException(
                'MEMBERSHIP_BOOKING_CHANGED',
                409,
                __('This membership booking changed. Reload it before continuing.'),
                ['current_booking_revision' => (int) $mapping->booking_revision],
            );
        }
        if ($mapping->order?->status === 'Cancelled'
            || $mapping->funding->whereIn('state', ['reserved', 'invoiced'])->isEmpty()) {
            throw new PaymentCheckoutException('MEMBERSHIP_BOOKING_NOT_ACTIVE', 409, __('This membership booking is no longer active.'));
        }

        return $mapping;
    }

    private function assertBeforeDeadline(MealSubscriptionOrder $mapping): void
    {
        $rows = $mapping->funding->whereIn('state', ['reserved', 'invoiced']);
        $deadlines = $rows->map(fn (MembershipBookingFunding $row): string => $row->change_deadline_at->format('Y-m-d H:i:s'))->unique();
        $timezones = $rows->pluck('booking_timezone')->map(fn ($value): string => (string) $value)->unique();
        if ($deadlines->count() !== 1 || $timezones->count() !== 1) {
            throw new \RuntimeException('Membership booking cutoff evidence is inconsistent.');
        }
        $deadline = CarbonImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            (string) $deadlines->first(),
            (string) $timezones->first(),
        );
        if (CarbonImmutable::now($deadline->getTimezone())->greaterThanOrEqualTo($deadline)) {
            throw new PaymentCheckoutException(
                'MEMBERSHIP_BOOKING_CHANGE_CLOSED',
                422,
                __('This booking can no longer be changed online. Please contact us for help.'),
            );
        }
    }

    /** @param array<string, mixed> $request */
    private function validatedClientUuid(array $request): string
    {
        $clientUuid = strtolower(trim((string) ($request['client_uuid'] ?? '')));
        if (! Str::isUuid($clientUuid)) {
            throw ValidationException::withMessages(['client_uuid' => __('A valid booking identifier is required.')]);
        }

        return $clientUuid;
    }

    private function systemActorId(): int
    {
        $actorId = (int) config('payments.system_user_id');
        if ($actorId <= 0) {
            throw new PaymentCheckoutException('SYSTEM_ACTOR_MISSING', 503, __('The booking system actor is unavailable.'));
        }

        return $actorId;
    }

    /**
     * @param  array<string, mixed>  $requestSnapshot
     * @param  array<string, mixed>  $termsSnapshot
     * @param  array<string, mixed>  $result
     */
    private function recordOperation(
        User $user,
        int $customerId,
        int $companyId,
        int $branchId,
        MealSubscription $root,
        string $clientUuid,
        int $inputQueueRevision,
        string $requestFingerprint,
        array $requestSnapshot,
        array $termsSnapshot,
        array $result,
    ): MembershipBookingOperation {
        return MembershipBookingOperation::query()->create([
            'client_uuid' => $clientUuid,
            'portal_user_id' => $user->id,
            'customer_id' => $customerId,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'subscription_id' => $root->id,
            'input_queue_revision' => $inputQueueRevision,
            'request_fingerprint' => $requestFingerprint,
            'state' => 'completed',
            'request_snapshot' => $requestSnapshot,
            'terms_snapshot' => $termsSnapshot,
            'result_snapshot' => $result,
            'completed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $request */
    private function mutationFingerprint(string $action, array $request): string
    {
        return $this->canonicalizer->hash([
            'membership-booking-mutation-v1',
            $action,
            $request,
        ]);
    }

    private function assertReplayMatches(MembershipBookingOperation $operation, string $fingerprint): void
    {
        if (! hash_equals((string) $operation->request_fingerprint, $fingerprint)) {
            throw new PaymentCheckoutException('BOOKING_REQUEST_CHANGED', 409, __('This booking identifier was already used for different selections.'));
        }
    }

    /** @param array<string, mixed> $quote
     * @param  array<string, mixed>  $request
     */
    private function assertAcceptedQuote(array $quote, array $request): void
    {
        if (! $quote['can_book'] || ! $quote['quote_fingerprint']
            || ! hash_equals((string) $quote['quote_fingerprint'], (string) ($request['quote_fingerprint'] ?? ''))) {
            throw new PaymentCheckoutException(
                'MEMBERSHIP_BOOKING_QUOTE_CHANGED',
                409,
                __('Your membership booking changed. Review it before confirming.'),
                ['quote' => $this->publicQuote($quote)],
            );
        }
        if (! hash_equals((string) $quote['terms_version'], (string) ($request['accepted_terms_version'] ?? ''))) {
            throw new PaymentCheckoutException(
                'TERMS_CHANGED',
                409,
                __('Accept the current terms before confirming your meals.'),
                ['quote' => $this->publicQuote($quote)],
            );
        }
    }

    /** @param array<string, mixed> $request */
    private function requestFingerprint(array $request): string
    {
        return $this->canonicalizer->hash([
            'membership-booking-request-v1',
            (string) ($request['selected_branch_id'] ?? ''),
            trim((string) ($request['queue_reference'] ?? '')),
            (string) ($request['queue_revision'] ?? ''),
            $request['selections'] ?? [],
            (string) ($request['quote_fingerprint'] ?? ''),
            (string) ($request['accepted_terms_version'] ?? ''),
        ]);
    }

    /** @param array<string, mixed> $quote
     * @return array<string, mixed>
     */
    private function publicQuote(array $quote): array
    {
        return array_diff_key($quote, array_flip(['_context', '_priced_days']));
    }

    private function decimalCents(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT).'0';
    }
}

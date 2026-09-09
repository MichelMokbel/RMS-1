<?php

namespace App\Services\Subscriptions;

use App\Models\MealSubscriptionOrder;
use App\Models\User;
use App\Services\Customers\CustomerIdentityResolver;
use App\Services\Payments\CheckoutCanonicalizer;
use App\Services\Payments\OrdinaryOrderQuoteService;
use App\Services\Payments\PaymentCheckoutException;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class MembershipBookingQuoteService
{
    public function __construct(
        private readonly CustomerIdentityResolver $identityResolver,
        private readonly OrdinaryOrderQuoteService $ordinaryQuotes,
        private readonly MembershipQueueService $queues,
        private readonly CheckoutCanonicalizer $canonicalizer,
    ) {}

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function quote(User $user, array $request): array
    {
        if (! (bool) config('payments.membership.queue_enabled', false)
            || ! (bool) config('payments.membership.booking_enabled', false)) {
            throw new PaymentCheckoutException('MEMBERSHIP_BOOKING_DISABLED', 503, __('Membership meal booking is not available yet.'));
        }

        $selectedBranchId = (int) ($request['selected_branch_id'] ?? 0);
        $configuredBranchId = (int) config('payments.public_order_branch_id', 1);
        if ($selectedBranchId !== $configuredBranchId) {
            throw ValidationException::withMessages(['selected_branch_id' => __('This membership branch is unavailable.')]);
        }

        $user = $this->identityResolver->resolveForCheckout($user);
        $rawSelections = $request['selections'] ?? null;
        if (! is_array($rawSelections) || $rawSelections === []) {
            throw ValidationException::withMessages(['selections' => __('Select at least one future main dish.')]);
        }

        $normalized = $this->ordinaryQuotes->normalizeCart(['items' => $rawSelections]);
        $membershipSelections = array_map(function (array $day): array {
            $mainQuantity = 0;
            foreach ($day['mains'] as $main) {
                if ($main['portion'] !== 'plate') {
                    throw ValidationException::withMessages([
                        'selections' => __('Membership meals support plate main dishes only.'),
                    ]);
                }
                $mainQuantity += (int) $main['qty'];
            }

            return [
                'key' => $day['date'],
                'mains' => $day['mains'],
                'salad_qty' => $mainQuantity,
                'dessert_qty' => $mainQuantity,
                'notes' => $day['notes'],
                'add_ons' => $day['add_ons'],
            ];
        }, $normalized);

        $ordinary = $this->ordinaryQuotes->quote($user, [
            'cart' => ['items' => $membershipSelections],
        ]);
        $context = $ordinary['_context'];
        $queue = $this->queues->summary(
            (int) $user->customer_id,
            (int) $context['company_id'],
            (int) $context['branch']->id,
        );
        $queueReference = trim((string) ($request['queue_reference'] ?? ''));
        if (! $queue['queue_reference'] || ! hash_equals((string) $queue['queue_reference'], $queueReference)) {
            throw new PaymentCheckoutException(
                'MEMBERSHIP_NOT_AVAILABLE_FOR_BRANCH',
                422,
                __('No paid membership allowance is available for this branch.'),
                ['support_phone' => $context['settings']->order_support_phone, 'can_buy_membership' => true],
            );
        }
        $replacement = $this->replacementContext(
            $user,
            $request,
            (int) $context['company_id'],
            (int) $context['branch']->id,
        );
        $availableForRequest = (int) $queue['available_meals'] + (int) ($replacement['main_quantity'] ?? 0);

        $acceptedDays = $ordinary['_priced_days'];
        $pausedDates = collect($acceptedDays)
            ->pluck('date')
            ->filter(fn (string $date): bool => collect($queue['pause_periods'])->contains(
                fn (array $period): bool => $date >= $period['start'] && $date <= $period['end'],
            ))
            ->values()
            ->all();
        if ($pausedDates !== []) {
            throw new PaymentCheckoutException(
                'MEMBERSHIP_PAUSED_FOR_DATE',
                422,
                __('Your membership is paused for one or more selected dates.'),
                ['paused_dates' => $pausedDates],
            );
        }
        $mainQuantity = collect($acceptedDays)->sum(fn (array $day): int => collect($day['submission']['mains'])
            ->sum(fn (array $main): int => (int) $main['qty']));
        $addOnTotalCents = (int) collect($acceptedDays)->sum('add_on_total_cents');
        if ($mainQuantity <= 0) {
            return [
                'result_kind' => 'covered_booking',
                'selections' => [],
                'excluded_today' => $ordinary['excluded_today'],
                'main_quantity' => 0,
                'payable_amount_cents' => 0,
                'currency' => 'QAR',
                'quote_fingerprint' => null,
                'terms_version' => $ordinary['terms_version'],
                'terms_url' => $ordinary['terms_url'],
                'support_phone' => $ordinary['support_phone'],
                'queue' => $queue,
                'replacement' => $replacement ? $this->publicReplacement($replacement) : null,
                'can_book' => false,
            ];
        }
        if ($replacement && count($acceptedDays) !== 1) {
            throw new PaymentCheckoutException(
                'MEMBERSHIP_REPLACEMENT_REQUIRES_ONE_DATE',
                422,
                __('A membership booking can be changed to one future date at a time.'),
            );
        }
        if ($mainQuantity > $availableForRequest) {
            throw new PaymentCheckoutException(
                'INSUFFICIENT_MEMBERSHIP_BALANCE',
                422,
                __('Your membership has :available meals available, but :selected were selected.', [
                    'available' => $availableForRequest,
                    'selected' => $mainQuantity,
                ]),
                [
                    'queue' => $queue,
                    'available_after_releasing_current_booking' => $availableForRequest,
                    'can_buy_membership' => true,
                ],
            );
        }

        if ($replacement) {
            $newDate = (string) $acceptedDays[0]['date'];
            $newDeadline = $newDate === $replacement['service_date']
                ? $replacement['change_deadline']
                : CarbonImmutable::createFromFormat(
                    '!Y-m-d H:i:s',
                    $newDate.' '.$replacement['booking_cutoff_time'],
                    $replacement['booking_timezone'],
                )->subDay();
            $this->assertBeforeDeadline($newDeadline);
            $replacement['new_change_deadline'] = $newDeadline;
        }

        $canonicalDays = array_map(fn (array $day): array => $day['canonical_tuple'], $acceptedDays);
        $quoteFingerprint = $this->canonicalizer->hash([
            'membership-booking-quote-v1',
            (string) $user->customer_id,
            (string) $context['company_id'],
            (string) $context['branch']->id,
            (string) $queueReference,
            (string) $queue['queue_revision'],
            (string) ($replacement['booking_reference'] ?? ''),
            (string) ($replacement['booking_revision'] ?? ''),
            $replacement ? $replacement['new_change_deadline']->toIso8601String() : '',
            $canonicalDays,
            $addOnTotalCents,
            (string) $ordinary['terms_version'],
            (string) $ordinary['terms_content_hash'],
        ]);

        return [
            'result_kind' => 'covered_booking',
            'queue_reference' => $queueReference,
            'queue_revision' => (int) $queue['queue_revision'],
            'selections' => array_map(fn (array $day): array => $day['submission'], $acceptedDays),
            'excluded_today' => $ordinary['excluded_today'],
            'main_quantity' => $mainQuantity,
            'payable_amount_cents' => $addOnTotalCents,
            'add_on_amount_cents' => $addOnTotalCents,
            'currency' => 'QAR',
            'quote_fingerprint' => $quoteFingerprint,
            'terms_version' => $ordinary['terms_version'],
            'terms_url' => $ordinary['terms_url'],
            'terms_content_hash' => $ordinary['terms_content_hash'],
            'support_phone' => $ordinary['support_phone'],
            'current_qatar_date' => now('Asia/Qatar')->toDateString(),
            'queue' => $queue,
            'available_after_releasing_current_booking' => $availableForRequest,
            'replacement' => $replacement ? $this->publicReplacement($replacement) : null,
            'can_book' => true,
            'requires_payment' => $addOnTotalCents > 0,
            '_context' => $context,
            '_priced_days' => $acceptedDays,
            '_replacement' => $replacement,
        ];
    }

    /** @return array<string, mixed>|null */
    private function replacementContext(User $user, array $request, int $companyId, int $branchId): ?array
    {
        $reference = trim((string) ($request['booking_reference'] ?? ''));
        $requestedRevision = (int) ($request['booking_revision'] ?? 0);
        if ($reference === '' && $requestedRevision === 0) {
            return null;
        }
        if ($reference === '' || $requestedRevision <= 0) {
            throw ValidationException::withMessages([
                'booking_reference' => __('Both booking reference and revision are required to change a booking.'),
            ]);
        }

        $roots = $this->queues->compatibleRootsForRead(
            (int) $user->customer_id,
            $companyId,
            $branchId,
        );
        $mapping = MealSubscriptionOrder::query()
            ->with(['order', 'funding'])
            ->whereIn('subscription_id', $roots->pluck('id'))
            ->where('booking_uuid', $reference)
            ->orderByDesc('booking_revision')
            ->first();
        if (! $mapping) {
            throw new PaymentCheckoutException('MEMBERSHIP_BOOKING_NOT_FOUND', 404, __('Membership booking was not found.'));
        }
        if ((int) $mapping->booking_revision !== $requestedRevision) {
            throw new PaymentCheckoutException(
                'MEMBERSHIP_BOOKING_CHANGED',
                409,
                __('This membership booking changed. Reload it before continuing.'),
                ['current_booking_revision' => (int) $mapping->booking_revision],
            );
        }

        $activeFunding = $mapping->funding->whereIn('state', ['reserved', 'invoiced']);
        if ($mapping->order?->status === 'Cancelled' || $activeFunding->isEmpty()) {
            throw new PaymentCheckoutException('MEMBERSHIP_BOOKING_NOT_ACTIVE', 409, __('This membership booking is no longer active.'));
        }
        $deadlines = $activeFunding
            ->map(fn ($row): string => $row->change_deadline_at->format('Y-m-d H:i:s'))
            ->unique();
        $cutoffs = $activeFunding->pluck('booking_cutoff_time')->map(fn ($value): string => (string) $value)->unique();
        $timezones = $activeFunding->pluck('booking_timezone')->map(fn ($value): string => (string) $value)->unique();
        if ($deadlines->count() !== 1 || $cutoffs->count() !== 1 || $timezones->count() !== 1) {
            throw new \RuntimeException('Membership booking cutoff evidence is inconsistent.');
        }
        $timezone = (string) $timezones->first();
        $deadline = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', (string) $deadlines->first(), $timezone);
        $this->assertBeforeDeadline($deadline);

        return [
            'mapping' => $mapping,
            'booking_reference' => (string) $mapping->booking_uuid,
            'booking_revision' => (int) $mapping->booking_revision,
            'service_date' => $mapping->service_date->toDateString(),
            'main_quantity' => (int) $activeFunding->sum('main_quantity'),
            'booking_cutoff_time' => (string) $cutoffs->first(),
            'booking_timezone' => $timezone,
            'change_deadline' => $deadline,
        ];
    }

    private function assertBeforeDeadline(CarbonImmutable $deadline): void
    {
        if (CarbonImmutable::now($deadline->getTimezone())->greaterThanOrEqualTo($deadline)) {
            throw new PaymentCheckoutException(
                'MEMBERSHIP_BOOKING_CHANGE_CLOSED',
                422,
                __('This booking can no longer be changed online. Please contact us for help.'),
            );
        }
    }

    /** @param array<string, mixed> $replacement
     * @return array<string, mixed>
     */
    private function publicReplacement(array $replacement): array
    {
        return [
            'booking_reference' => $replacement['booking_reference'],
            'booking_revision' => $replacement['booking_revision'],
            'service_date' => $replacement['service_date'],
            'current_main_quantity' => $replacement['main_quantity'],
            'change_deadline_at' => $replacement['change_deadline']->toIso8601String(),
            'new_change_deadline_at' => isset($replacement['new_change_deadline'])
                ? $replacement['new_change_deadline']->toIso8601String()
                : null,
        ];
    }
}

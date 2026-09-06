<?php

namespace App\Services\Payments\Consistency;

use App\Models\AccountingAuditLog;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\Accounting\AccountingContextService;
use App\Services\Customers\CustomerMergeService;
use App\Services\Customers\CustomerOwnershipService;
use App\Services\Payments\PaymentCreditProjectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CustomerCreditConsistencyRule implements PaymentConsistencyRule
{
    public const OWNERSHIP = 'customer_ownership_v1';

    public const CREDIT = 'saved_credit_v1';

    public function __construct(
        private readonly AccountingContextService $accountingContext,
        private readonly CustomerOwnershipService $customerOwnership,
        private readonly PaymentCreditProjectionService $creditProjection,
    ) {}

    public function ruleCodes(): array
    {
        return [self::OWNERSHIP, self::CREDIT];
    }

    public function scope(string $ruleCode, string $subjectType, int $subjectId): array
    {
        if ($ruleCode === self::OWNERSHIP && $subjectType === 'customer') {
            Customer::query()->findOrFail($subjectId);

            return [
                'company_id' => $this->defaultCompanyId(),
                'branch_id' => null,
                'checkout_id' => null,
            ];
        }
        if ($ruleCode === self::CREDIT && $subjectType === 'payment') {
            $payment = Payment::query()->findOrFail($subjectId);
            $scope = $this->creditScope($payment);

            return [
                'company_id' => $scope['company_id'],
                'branch_id' => $scope['branch_id'],
                'checkout_id' => DB::table('payment_provider_transactions')->where('payment_id', $payment->id)->value('attempt_id'),
            ];
        }

        throw new \InvalidArgumentException('Unsupported customer or credit consistency subject.');
    }

    public function evaluate(string $ruleCode, string $subjectType, int $subjectId): array
    {
        return match ($ruleCode) {
            self::OWNERSHIP => $this->evaluateOwnership($subjectType, $subjectId),
            self::CREDIT => $this->evaluateCredit($subjectType, $subjectId),
            default => throw new \InvalidArgumentException('Unsupported customer or credit consistency rule.'),
        };
    }

    private function evaluateOwnership(string $subjectType, int $subjectId): array
    {
        if ($subjectType !== 'customer') {
            throw new \InvalidArgumentException('Customer ownership checks require a customer subject.');
        }
        $subject = Customer::query()->findOrFail($subjectId);
        $issues = [];

        try {
            $canonicalId = $this->customerOwnership->canonicalCustomerId((int) $subject->id);
            $historicalIds = $this->customerOwnership->historicalCustomerIds($canonicalId);
        } catch (Throwable) {
            $canonicalId = (int) $subject->id;
            $historicalIds = [$canonicalId];
            $issues[] = ConsistencyEvidence::issue('CUSTOMER_MERGE_CHAIN_INVALID', 'customer', (int) $subject->id);
        }

        $customers = DB::table('customers')->whereIn('id', $historicalIds)->orderBy('id')->get([
            'id', 'merged_into_customer_id', 'is_active', 'phone_verified_at', 'created_at', 'updated_at',
        ]);
        $users = DB::table('users')->whereIn('customer_id', $historicalIds)->orderBy('id')->get([
            'id', 'customer_id', 'status', 'remember_token', 'portal_phone_verified_at', 'created_at', 'updated_at',
        ]);
        $reviews = DB::table('customer_match_reviews')
            ->whereIn('customer_id', $historicalIds)->orWhereIn('candidate_customer_id', $historicalIds)
            ->orderBy('id')->get([
                'id', 'user_id', 'customer_id', 'candidate_customer_id', 'profile_fingerprint', 'status',
                'reviewed_by', 'reviewed_at', 'merge_audit_id', 'created_at', 'updated_at',
            ]);
        $mergeAudits = DB::table('accounting_audit_logs')
            ->where('action', 'customer.merged')
            ->where(function ($query) use ($historicalIds): void {
                $query->whereIn('subject_id', $historicalIds);
                foreach ($historicalIds as $customerId) {
                    $query->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.source_customer_id')) = ?", [(string) $customerId])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.destination_customer_id')) = ?", [(string) $customerId]);
                }
            })->orderBy('id')->get(['id', 'subject_id', 'actor_id', 'created_at']);

        $canonical = $customers->firstWhere('id', $canonicalId);
        if (! $canonical || ! (bool) $canonical->is_active || $canonical->merged_into_customer_id !== null) {
            $issues[] = ConsistencyEvidence::issue('CUSTOMER_CANONICAL_OWNER_INACTIVE', 'customer', $canonicalId);
        }
        $sourceIds = array_values(array_diff($historicalIds, [$canonicalId]));
        foreach ($customers->whereIn('id', $sourceIds) as $source) {
            if ((bool) $source->is_active || (int) $source->merged_into_customer_id <= 0) {
                $issues[] = ConsistencyEvidence::issue('CUSTOMER_MERGED_SOURCE_ACTIVE', 'customer', (int) $source->id);
            }
        }
        $activeUsers = $users->where('status', 'active');
        if ($activeUsers->count() > 1 || $activeUsers->contains(fn ($user): bool => (int) $user->customer_id !== $canonicalId)) {
            $issues[] = ConsistencyEvidence::issue('CUSTOMER_LOGIN_SURVIVOR_MISMATCH', 'customer', $canonicalId);
        }
        foreach ($users->whereIn('customer_id', $sourceIds) as $sourceUser) {
            $tokenCount = Schema::hasTable('personal_access_tokens')
                ? DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->where('tokenable_id', $sourceUser->id)->count()
                : 0;
            $sessionCount = Schema::hasTable('sessions')
                ? DB::table('sessions')->where('user_id', $sourceUser->id)->count()
                : 0;
            if ($sourceUser->status === 'active' || $sourceUser->remember_token !== null || $tokenCount > 0 || $sessionCount > 0) {
                $issues[] = ConsistencyEvidence::issue('CUSTOMER_SOURCE_LOGIN_NOT_REVOKED', 'user', (int) $sourceUser->id);
            }
        }

        $liveCounts = [];
        foreach (CustomerMergeService::LIVE_CUSTOMER_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'customer_id')) {
                throw new \RuntimeException("Required customer ownership reader is unavailable: {$table}.customer_id");
            }
            $count = $sourceIds === [] ? 0 : DB::table($table)->whereIn('customer_id', $sourceIds)->count();
            $liveCounts[$table] = $count;
            if ($count > 0) {
                $issues[] = ConsistencyEvidence::issue('CUSTOMER_LIVE_RECORD_ON_MERGED_SOURCE', $table, (int) $subject->id);
            }
        }

        return ConsistencyEvidence::result(
            $this->defaultCompanyId(),
            null,
            null,
            [
                'canonical_customer_id' => $canonicalId,
                'canonical_owner_active' => true,
                'merged_sources_inactive' => true,
                'at_most_one_active_login_on_destination' => true,
                'live_records_on_destination' => true,
            ],
            [
                'historical_customer_ids' => $historicalIds,
                'active_login_ids' => $activeUsers->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                'live_source_counts' => $liveCounts,
                'review_ids' => $reviews->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'merge_audit_ids' => $mergeAudits->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ],
            [
                'customers' => $customers->map(fn ($row): array => (array) $row)->all(),
                'users' => $users->map(fn ($row): array => [
                    'id' => (int) $row->id,
                    'customer_id' => (int) $row->customer_id,
                    'status' => (string) $row->status,
                    'remember_token_present' => $row->remember_token !== null,
                    'portal_phone_verified_at' => $row->portal_phone_verified_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ])->all(),
                'reviews' => $reviews->map(fn ($row): array => (array) $row)->all(),
                'merge_audits' => $mergeAudits->map(fn ($row): array => (array) $row)->all(),
                'live_source_counts' => $liveCounts,
            ],
            $issues,
        );
    }

    private function evaluateCredit(string $subjectType, int $subjectId): array
    {
        if ($subjectType !== 'payment') {
            throw new \InvalidArgumentException('Saved credit checks require a payment subject.');
        }
        $payment = Payment::query()->findOrFail($subjectId);
        $scope = $this->creditScope($payment);
        $allocations = DB::table('payment_allocations')->where('payment_id', $payment->id)->orderBy('id')->get([
            'id', 'payment_id', 'allocatable_type', 'allocatable_id', 'amount_cents', 'voided_at',
            'voided_by', 'created_at', 'updated_at',
        ]);
        $invoiceIds = $allocations->where('allocatable_type', ArInvoice::class)->pluck('allocatable_id')->filter();
        $invoices = DB::table('ar_invoices')->whereIn('id', $invoiceIds)->orderBy('id')->get([
            'id', 'company_id', 'branch_id', 'customer_id', 'currency', 'status', 'total_cents',
            'paid_total_cents', 'balance_cents', 'voided_at', 'created_at', 'updated_at',
        ])->keyBy('id');
        $blocks = DB::table('membership_purchase_blocks')->where('payment_id', $payment->id)->orderBy('id')->get([
            'id', 'subscription_id', 'payment_id', 'company_id', 'branch_id', 'original_customer_id',
            'queue_position', 'meal_count', 'gross_price_cents', 'discount_cents', 'final_price_cents',
            'currency', 'opening_used_quantity', 'opening_released_quantity', 'funded_at', 'cancelled_at',
            'created_at', 'updated_at',
        ]);
        $funding = DB::table('membership_booking_funding')->whereIn('purchase_block_id', $blocks->pluck('id'))
            ->orderBy('id')->get([
                'id', 'purchase_block_id', 'subscription_order_id', 'main_quantity', 'position_ranges',
                'invoice_id', 'invoice_net_cents', 'payment_allocation_id', 'state', 'reserved_at',
                'invoiced_at', 'released_at', 'created_at', 'updated_at',
            ]);
        $allocationAudits = AccountingAuditLog::query()
            ->where('subject_type', Payment::class)->where('subject_id', $payment->id)
            ->whereIn('action', [
                'ar_payment.created',
                'payment.saved_credit_allocation.accepted',
                'payment.saved_credit_allocation.completed',
            ])
            ->orderBy('id')->get(['id', 'action', 'actor_id', 'payload', 'created_at']);
        $checkoutAllocationIds = DB::table('payment_allocations as allocations')
            ->join('payment_checkout_targets as targets', function ($join): void {
                $join->on('targets.invoice_id', '=', 'allocations.allocatable_id')
                    ->where('allocations.allocatable_type', '=', ArInvoice::class);
            })
            ->join('payment_provider_transactions as transactions', 'transactions.attempt_id', '=', 'targets.attempt_id')
            ->where('allocations.payment_id', $payment->id)
            ->where('transactions.payment_id', $payment->id)
            ->pluck('allocations.id')
            ->map(fn ($id): int => (int) $id);
        $bookingAllocationIds = $funding->pluck('payment_allocation_id')->filter()->map(fn ($id): int => (int) $id);
        $receiptAllocationIds = $this->initialReceiptAllocationIds($allocationAudits, $allocations);
        $workflowAllocationIds = $checkoutAllocationIds
            ->merge($bookingAllocationIds)
            ->merge($receiptAllocationIds)
            ->unique();
        $unauditedAllocationIds = [];
        $issues = [];
        $savedCreditApplicable = $payment->source === 'ar'
            && $payment->voided_at === null
            && (int) $payment->amount_cents > 0;

        try {
            $projection = $this->creditProjection->project($payment);
        } catch (Throwable) {
            $projection = [
                'state' => 'unavailable', 'reason_code' => 'CREDIT_PROJECTION_FAILED',
                'amount_cents' => (int) $payment->amount_cents, 'allocated_cents' => 0,
                'unallocated_cents' => 0, 'committed_cents' => 0, 'available_cents' => 0,
                'committed_subscription_ids' => [],
            ];
        }
        if ($savedCreditApplicable && $projection['state'] === 'unavailable') {
            $issues[] = ConsistencyEvidence::issue((string) ($projection['reason_code'] ?: 'PAYMENT_CREDIT_INCONSISTENT'), 'payment', (int) $payment->id);
        }
        foreach ($allocations->whereNull('voided_at') as $allocation) {
            if (! $savedCreditApplicable) {
                continue;
            }
            if ($allocation->allocatable_type !== ArInvoice::class) {
                $issues[] = ConsistencyEvidence::issue('CREDIT_ALLOCATION_TARGET_UNSUPPORTED', 'payment_allocation', (int) $allocation->id);

                continue;
            }
            $invoice = $invoices->get((int) $allocation->allocatable_id);
            if (! $invoice
                || (int) $invoice->company_id !== (int) $payment->company_id
                || (int) $invoice->branch_id !== (int) $payment->branch_id
                || (string) $invoice->currency !== (string) $payment->currency
                || ! $this->sameCustomer((int) $invoice->customer_id, (int) $payment->customer_id)
                || $invoice->voided_at !== null) {
                $issues[] = ConsistencyEvidence::issue('CREDIT_ALLOCATION_SCOPE_MISMATCH', 'payment_allocation', (int) $allocation->id);
            }
            if ($savedCreditApplicable
                && ! $workflowAllocationIds->contains((int) $allocation->id)
                && ! $this->hasCompletedAdminAllocationAudit($allocationAudits, (int) $allocation->id)) {
                $unauditedAllocationIds[] = (int) $allocation->id;
                $issues[] = ConsistencyEvidence::issue('CREDIT_ALLOCATION_ADMIN_AUDIT_MISSING', 'payment_allocation', (int) $allocation->id);
            }
        }

        return ConsistencyEvidence::result(
            $scope['company_id'],
            $scope['branch_id'],
            DB::table('payment_provider_transactions')->where('payment_id', $payment->id)->value('attempt_id'),
            [
                'available_cents_formula' => 'receipt_minus_active_allocations_and_active_membership_commitments',
                'allocation_scope_matches_payment' => true,
                'promotional_discount_is_not_credit' => true,
                'discretionary_allocation_has_completed_admin_audit' => true,
            ],
            [
                'payment_id' => (int) $payment->id,
                'amount_cents' => (int) $payment->amount_cents,
                'allocated_cents' => (int) $projection['allocated_cents'],
                'unallocated_cents' => (int) $projection['unallocated_cents'],
                'committed_cents' => (int) $projection['committed_cents'],
                'available_cents' => (int) $projection['available_cents'],
                'projection_state' => (string) $projection['state'],
                'committed_subscription_ids' => $projection['committed_subscription_ids'],
                'allocation_audit_ids' => $allocationAudits->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'receipt_allocation_ids' => $receiptAllocationIds->all(),
                'unaudited_allocation_ids' => $unauditedAllocationIds,
            ],
            [
                'payment' => $payment->only([
                    'id', 'customer_id', 'company_id', 'branch_id', 'payment_source_id', 'source', 'method',
                    'amount_cents', 'currency', 'received_at', 'voided_at', 'clearing_settled_at', 'created_at', 'updated_at',
                ]),
                'allocations' => $allocations->map(fn ($row): array => (array) $row)->all(),
                'invoices' => $invoices->values()->map(fn ($row): array => (array) $row)->all(),
                'blocks' => $blocks->map(fn ($row): array => (array) $row)->all(),
                'funding' => $funding->map(fn ($row): array => (array) $row)->all(),
                'allocation_audits' => $allocationAudits->map(fn (AccountingAuditLog $row): array => $row->only([
                    'id', 'action', 'actor_id', 'payload', 'created_at',
                ]))->all(),
            ],
            $issues,
        );
    }

    private function hasCompletedAdminAllocationAudit($audits, int $allocationId): bool
    {
        return $audits->where('action', 'payment.saved_credit_allocation.completed')->contains(
            function (AccountingAuditLog $completed) use ($audits, $allocationId): bool {
                $operationUuid = (string) ($completed->payload['operation_uuid'] ?? '');
                $allocationIds = array_map('intval', (array) ($completed->payload['allocation_ids'] ?? []));
                if ($operationUuid === '' || ! in_array($allocationId, $allocationIds, true)) {
                    return false;
                }

                return $audits->where('action', 'payment.saved_credit_allocation.accepted')->contains(
                    fn (AccountingAuditLog $accepted): bool => (int) $accepted->actor_id === (int) $completed->actor_id
                        && hash_equals($operationUuid, (string) ($accepted->payload['operation_uuid'] ?? '')),
                );
            },
        );
    }

    private function initialReceiptAllocationIds($audits, $allocations)
    {
        return $audits->where('action', 'ar_payment.created')->flatMap(function (AccountingAuditLog $audit) use ($allocations): array {
            $recordedIds = array_values(array_filter(
                array_map('intval', (array) ($audit->payload['allocation_ids'] ?? [])),
                fn (int $id): bool => $id > 0,
            ));
            if ($recordedIds !== []) {
                return $recordedIds;
            }

            $appliedCents = (int) ($audit->payload['applied_cents'] ?? 0);
            if ($appliedCents <= 0) {
                return [];
            }

            $eligible = $allocations->filter(
                fn ($allocation): bool => (string) $allocation->created_at <= (string) $audit->created_at,
            );
            if ((int) $eligible->sum('amount_cents') !== $appliedCents) {
                return [];
            }

            return $eligible->pluck('id')->map(fn ($id): int => (int) $id)->all();
        })->unique()->values();
    }

    private function defaultCompanyId(): int
    {
        $companyId = (int) ($this->accountingContext->defaultCompanyId() ?? 0);
        if ($companyId <= 0) {
            throw new \RuntimeException('The default company is unavailable for customer ownership checks.');
        }

        return $companyId;
    }

    private function sameCustomer(int $left, int $right): bool
    {
        try {
            return $left > 0 && $right > 0
                && $this->customerOwnership->canonicalCustomerId($left) === $this->customerOwnership->canonicalCustomerId($right);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{company_id:int,branch_id:int|null} */
    private function creditScope(Payment $payment): array
    {
        $invoice = DB::table('payment_allocations as allocations')
            ->join('ar_invoices as invoices', function ($join): void {
                $join->on('invoices.id', '=', 'allocations.allocatable_id')
                    ->where('allocations.allocatable_type', '=', ArInvoice::class);
            })
            ->where('allocations.payment_id', $payment->id)
            ->orderBy('allocations.id')
            ->first(['invoices.company_id', 'invoices.branch_id']);

        return [
            'company_id' => (int) ($payment->company_id ?: $invoice?->company_id ?: $this->defaultCompanyId()),
            'branch_id' => $payment->branch_id
                ? (int) $payment->branch_id
                : ($invoice?->branch_id ? (int) $invoice->branch_id : null),
        ];
    }
}

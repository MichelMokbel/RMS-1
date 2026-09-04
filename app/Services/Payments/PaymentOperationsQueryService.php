<?php

namespace App\Services\Payments;

use App\Models\PaymentCheckoutAttempt;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use Illuminate\Database\Eloquent\Builder;

class PaymentOperationsQueryService
{
    public function __construct(
        private readonly AccountingContextService $accountingContext,
        private readonly PaymentOperationsAccessService $access,
    ) {}

    /** @param array<string, mixed> $filters */
    public function query(User $actor, array $filters = []): Builder
    {
        $this->assertListAccess($actor);
        $companyId = $this->accountingContext->defaultCompanyId();
        $query = PaymentCheckoutAttempt::query()
            ->where('company_id', $companyId ?: 0)
            ->with([
                'customer.mergedIntoCustomer',
                'paymentSource',
                'targets.invoice',
                'providerTransactions' => fn ($query) => $query->with(['payment.allocations', 'activeClearingSettlement'])->orderByDesc('id'),
            ]);
        if (! $actor->isAdmin()) {
            $query->whereIn('branch_id', $actor->allowedBranchIds() ?: [0]);
        }

        $view = (string) ($filters['view'] ?? 'attention');
        if ($view !== 'all') {
            $now = now('UTC')->toIso8601String();
            $query->whereNotNull('operations_tracking')
                ->where(function (Builder $query) use ($now): void {
                    foreach (PaymentOperationsTrackingService::ISSUE_SLOTS as $slot) {
                        $query->orWhere(function (Builder $issue) use ($now, $slot): void {
                            $issue->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(operations_tracking, '$.issues.{$slot}.attention_at')) <= ?", [$now])
                                ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(operations_tracking, '$.issues.{$slot}.resolved_at')), 'null') = 'null'");
                        });
                    }
                });
        }

        $term = trim((string) ($filters['search'] ?? ''));
        if ($term !== '') {
            $query->where(function (Builder $query) use ($term): void {
                $query->where('reference', 'like', '%'.$term.'%')
                    ->orWhereHas('customer', fn (Builder $customer) => $customer->search($term))
                    ->orWhereHas('customer.mergedIntoCustomer', fn (Builder $customer) => $customer->search($term))
                    ->orWhereHas('providerTransactions', function (Builder $transaction) use ($term): void {
                        $transaction->where('provider_payment_id', 'like', '%'.$term.'%')
                            ->orWhere('merchant_transaction_id', 'like', '%'.$term.'%')
                            ->orWhereHas('payment', fn (Builder $payment) => $payment->where('reference', 'like', '%'.$term.'%'));
                    });
            });
        }

        $state = trim((string) ($filters['state'] ?? ''));
        if ($state !== '') {
            $query->where('state', $state);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', (string) $filters['date_to']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function find(User $actor, int $attemptId): PaymentCheckoutAttempt
    {
        $attempt = PaymentCheckoutAttempt::query()
            ->with([
                'customer.mergedIntoCustomer',
                'paymentSource',
                'targets.invoice',
                'targets.order',
                'providerTransactions' => fn ($query) => $query->with(['payment.allocations', 'activeClearingSettlement'])->orderByDesc('id'),
            ])
            ->findOrFail($attemptId);
        $this->access->assertCanView($actor, $attempt);

        return $attempt;
    }

    private function assertListAccess(User $actor): void
    {
        $probe = new PaymentCheckoutAttempt([
            'company_id' => $this->accountingContext->defaultCompanyId(),
            'branch_id' => $actor->isAdmin() ? 1 : ($actor->allowedBranchIds()[0] ?? 0),
        ]);
        $this->access->assertCanView($actor, $probe);
    }
}

<?php

namespace App\Services\Payments;

use App\Models\PaymentConsistencyFinding;
use App\Models\PaymentConsistencyRun;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PaymentConsistencyQueryService
{
    public function __construct(
        private readonly AccountingContextService $accountingContext,
        private readonly PaymentConsistencyAccessService $access,
    ) {}

    /** @param array<string, mixed> $filters */
    public function findings(User $actor, array $filters = []): Builder
    {
        $companyId = $this->companyId();
        $this->access->assertCanView($actor, $companyId, $actor->isAdmin() ? null : ($actor->allowedBranchIds()[0] ?? null));
        $query = PaymentConsistencyFinding::query()
            ->where('company_id', $companyId)
            ->with('lastRun');
        if (! $actor->isAdmin()) {
            $query->whereIn('branch_id', $actor->allowedBranchIds() ?: [0]);
        }
        if (in_array((string) ($filters['state'] ?? ''), [PaymentConsistencyFinding::STATE_OPEN, PaymentConsistencyFinding::STATE_RESOLVED], true)) {
            $query->where('state', $filters['state']);
        }
        $rule = trim((string) ($filters['rule'] ?? ''));
        if ($rule !== '') {
            $query->where('rule_code', $rule);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('last_seen_at', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('last_seen_at', '<=', (string) $filters['date_to']);
        }

        return $query->orderByRaw("CASE WHEN state = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id');
    }

    public function finding(User $actor, int $findingId): PaymentConsistencyFinding
    {
        $finding = PaymentConsistencyFinding::query()->with('lastRun')->findOrFail($findingId);
        $this->access->assertCanView(
            $actor,
            (int) $finding->company_id,
            $finding->branch_id ? (int) $finding->branch_id : null,
        );

        return $finding;
    }

    public function sourceUrl(User $actor, PaymentConsistencyFinding $finding): ?string
    {
        $this->access->assertCanView(
            $actor,
            (int) $finding->company_id,
            $finding->branch_id ? (int) $finding->branch_id : null,
        );
        if ($finding->checkout_id) {
            return route('receivables.payments.skipcash.show', (int) $finding->checkout_id);
        }

        return match ((string) $finding->subject_type) {
            'customer' => route('customers.edit', (int) $finding->subject_id),
            'payment' => route('receivables.payments.show', (int) $finding->subject_id),
            'meal_plan_request' => route('meal-plan-requests.show', (int) $finding->subject_id),
            'meal_subscription' => route('subscriptions.show', (int) $finding->subject_id),
            'membership_purchase_block' => $this->relatedRoute(
                'membership_purchase_blocks',
                (int) $finding->subject_id,
                'subscription_id',
                'subscriptions.show',
            ),
            'meal_subscription_order' => $this->relatedRoute(
                'meal_subscription_orders',
                (int) $finding->subject_id,
                'subscription_id',
                'subscriptions.show',
            ),
            'membership_promotion' => route('membership-promotions.show', (int) $finding->subject_id),
            'gateway_settlement_import' => route('accounting.ar-clearing.skipcash.show', (int) $finding->subject_id),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function health(User $actor): array
    {
        $companyId = $this->companyId();
        $this->access->assertCanView($actor, $companyId, $actor->isAdmin() ? null : ($actor->allowedBranchIds()[0] ?? null));
        $query = PaymentConsistencyRun::query()
            ->where('company_id', $companyId)
            ->where('rule_code', PaymentConsistencySweepService::PARENT_RULE_CODE)
            ->where('target_type', PaymentConsistencySweepService::PARENT_TARGET_TYPE)
            ->where('target_id', $companyId);
        $now = CarbonImmutable::now('UTC');
        $latestCompleted = (clone $query)->where('state', PaymentConsistencyRun::STATE_COMPLETED)
            ->orderByDesc('completed_at')->first();
        $latestCatchup = (clone $query)->where('kind', PaymentConsistencyRun::KIND_CATCHUP)
            ->where('state', PaymentConsistencyRun::STATE_COMPLETED)->orderByDesc('completed_at')->first();
        $latestFull = (clone $query)->where('kind', PaymentConsistencyRun::KIND_FULL)
            ->where('state', PaymentConsistencyRun::STATE_COMPLETED)->orderByDesc('completed_at')->first();
        $running = (clone $query)->whereIn('state', [PaymentConsistencyRun::STATE_QUEUED, PaymentConsistencyRun::STATE_RUNNING])->count();
        $stalled = (clone $query)->where('state', PaymentConsistencyRun::STATE_RUNNING)
            ->where(fn (Builder $builder) => $builder->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<', $now->subMinutes(10)))
            ->count();
        $failed = (clone $query)->where('state', PaymentConsistencyRun::STATE_FAILED)
            ->where('created_at', '>=', $now->subHours(26))->count();
        $open = PaymentConsistencyFinding::query()->where('company_id', $companyId)
            ->where('state', PaymentConsistencyFinding::STATE_OPEN)
            ->when(! $actor->isAdmin(), fn (Builder $builder) => $builder->whereIn('branch_id', $actor->allowedBranchIds() ?: [0]))
            ->count();

        $status = ! (bool) config('payment_consistency.enabled', false) ? 'disabled'
            : ($stalled > 0 ? 'stalled'
                : ($failed > 0 ? 'failed'
                    : ($latestCatchup && $latestFull
                        && $latestCatchup->completed_at?->gte($now->subMinutes(30))
                        && $latestFull->completed_at?->gte($now->subHours(26))
                        ? ($open > 0 ? 'issues' : 'healthy')
                        : ($running > 0 ? 'running' : 'stale'))));

        return [
            'status' => $status,
            'open_findings' => $open,
            'running_runs' => $running,
            'stalled_runs' => $stalled,
            'recent_failed_runs' => $failed,
            'last_success_at' => $latestCompleted?->completed_at,
            'last_catchup_at' => $latestCatchup?->completed_at,
            'last_full_at' => $latestFull?->completed_at,
        ];
    }

    private function companyId(): int
    {
        $companyId = (int) ($this->accountingContext->defaultCompanyId() ?? 0);
        if ($companyId <= 0) {
            throw new \RuntimeException('The default company is unavailable.');
        }

        return $companyId;
    }

    private function relatedRoute(string $table, int $id, string $foreignKey, string $routeName): ?string
    {
        $relatedId = DB::table($table)->where('id', $id)->value($foreignKey);

        return $relatedId ? route($routeName, (int) $relatedId) : null;
    }
}

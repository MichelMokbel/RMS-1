<?php

namespace App\Services\Payments;

use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PaymentOperationsHealthService
{
    public function __construct(
        private readonly AccountingContextService $accountingContext,
        private readonly PaymentOperationsAccessService $access,
        private readonly PaymentSetupService $setup,
    ) {}

    /** @param array<string, int> $metrics */
    public function recordSuccess(string $kind, array $metrics = []): bool
    {
        return $this->writeMarker($kind, 'succeeded', null, $metrics);
    }

    public function recordFailure(string $kind, string $errorCode): bool
    {
        return $this->writeMarker($kind, 'failed', $this->boundedCode($errorCode), []);
    }

    /** @return array<string, mixed> */
    public function summary(User $actor): array
    {
        $companyId = $this->accountingContext->defaultCompanyId();
        $probe = new PaymentCheckoutAttempt([
            'company_id' => $companyId,
            'branch_id' => $actor->isAdmin() ? 1 : ($actor->allowedBranchIds()[0] ?? 0),
        ]);
        $this->access->assertCanView($actor, $probe);

        $setup = $this->setup->inspectForNewCheckout();
        $query = PaymentCheckoutAttempt::query()
            ->where('company_id', $companyId ?: 0);
        if (! $actor->isAdmin()) {
            $query->whereIn('branch_id', $actor->allowedBranchIds() ?: [0]);
        }

        $now = CarbonImmutable::now('UTC');
        $oldestOverdue = (clone $query)
            ->whereNotNull('operations_next_action_at')
            ->where('operations_next_action_at', '<=', $now)
            ->orderBy('operations_next_action_at')
            ->value('operations_next_action_at');
        $overdueCount = (clone $query)
            ->whereNotNull('operations_next_action_at')
            ->where('operations_next_action_at', '<=', $now)
            ->count();

        return [
            'collection_enabled' => (bool) config('payments.skipcash.enabled', false),
            'legacy_direct_order_enabled' => (bool) config('payments.customer_direct_order_enabled', true),
            'configuration_state' => $setup['ready'] ? 'ready' : 'not_ready',
            'configuration_codes' => array_values(array_column($setup['errors'], 'code')),
            'recovery' => $this->markerSummary('recovery', 5),
            'purge' => $this->markerSummary('purge', 26 * 60),
            'overdue_count' => $overdueCount,
            'oldest_overdue_at' => $oldestOverdue
                ? CarbonImmutable::parse((string) $oldestOverdue)->toIso8601String()
                : null,
            'last_worker_result' => $this->latestWorkerResult($query),
        ];
    }

    /** @param array<string, int> $metrics */
    private function writeMarker(string $kind, string $result, ?string $errorCode, array $metrics): bool
    {
        if (! in_array($kind, ['recovery', 'purge'], true)) {
            return false;
        }

        try {
            $key = $this->key($kind);
            $previous = Cache::get($key);
            $marker = is_array($previous) ? $previous : [];
            $now = CarbonImmutable::now('UTC')->toIso8601String();
            if ($result === 'succeeded') {
                $marker['last_success_at'] = $now;
            }
            $marker['last_result_at'] = $now;
            $marker['last_result'] = $result;
            $marker['error_code'] = $errorCode;
            $marker['metrics'] = collect($metrics)
                ->filter(fn ($value, $key): bool => is_string($key) && is_int($value))
                ->all();
            Cache::forever($key, $marker);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function markerSummary(string $kind, int $staleAfterMinutes): array
    {
        try {
            $marker = Cache::get($this->key($kind));
        } catch (Throwable) {
            $marker = null;
        }
        if (! is_array($marker) || empty($marker['last_success_at'])) {
            return [
                'freshness' => 'unknown',
                'last_success_at' => null,
                'last_result' => is_array($marker) ? ($marker['last_result'] ?? 'unknown') : 'unknown',
                'last_result_at' => is_array($marker) ? ($marker['last_result_at'] ?? null) : null,
                'error_code' => is_array($marker) ? ($marker['error_code'] ?? null) : null,
            ];
        }

        try {
            $successAt = CarbonImmutable::parse((string) $marker['last_success_at']);
            $freshness = $successAt->addMinutes($staleAfterMinutes)->isPast() ? 'stale' : 'healthy';
        } catch (Throwable) {
            $freshness = 'unknown';
        }

        return [
            'freshness' => $freshness,
            'last_success_at' => $marker['last_success_at'],
            'last_result' => $marker['last_result'] ?? 'unknown',
            'last_result_at' => $marker['last_result_at'] ?? null,
            'error_code' => $marker['error_code'] ?? null,
        ];
    }

    private function key(string $kind): string
    {
        $companyId = $this->accountingContext->defaultCompanyId() ?: 0;
        $sourceId = PaymentSource::query()
            ->where('company_id', $companyId)
            ->where('code', PaymentSource::CODE_SKIPCASH)
            ->value('id') ?: 0;
        $environment = preg_replace('/[^a-z0-9_-]/i', '_', (string) config('app.env', 'unknown'));

        return "payments:operations:health:{$environment}:company:{$companyId}:source:{$sourceId}:{$kind}";
    }

    private function boundedCode(string $errorCode): string
    {
        $code = strtoupper(preg_replace('/[^A-Z0-9_]/i', '', $errorCode) ?: 'COMMAND_FAILED');

        return substr($code, 0, 80);
    }

    /** @return array<string, mixed>|null */
    private function latestWorkerResult($query): ?array
    {
        $attempts = (clone $query)
            ->whereNotNull('operations_tracking')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'operations_tracking']);
        $latest = null;

        foreach ($attempts as $attempt) {
            foreach (['recovery', 'resend'] as $kind) {
                $operation = data_get($attempt->operations_tracking, $kind);
                if (! is_array($operation)) {
                    continue;
                }
                $observedAt = $operation['completed_at'] ?? $operation['started_at'] ?? $operation['queued_at'] ?? null;
                if (! is_string($observedAt) || $observedAt === '') {
                    continue;
                }
                try {
                    $candidate = CarbonImmutable::parse($observedAt);
                } catch (Throwable) {
                    continue;
                }
                if ($latest === null || $candidate->greaterThan($latest['time'])) {
                    $latest = [
                        'time' => $candidate,
                        'attempt_id' => (int) $attempt->id,
                        'kind' => $kind,
                        'state' => (string) ($operation['state'] ?? 'unknown'),
                        'observed_at' => $candidate->toIso8601String(),
                    ];
                }
            }
        }

        if ($latest === null) {
            return null;
        }
        unset($latest['time']);

        return $latest;
    }
}

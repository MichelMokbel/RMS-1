<?php

namespace App\Services\Payments;

use App\Jobs\SendPaymentConsistencyAlert;
use App\Models\PaymentConsistencyFinding;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentConsistencyAlertService
{
    public function __construct(
        private readonly AccountingAuditLogService $auditLog,
    ) {}

    public function queueFinding(int $findingId, string $episodeUuid): bool
    {
        if (! (bool) config('payment_consistency.enabled', false)) {
            return false;
        }

        $queued = DB::transaction(function () use ($findingId, $episodeUuid): bool {
            $finding = PaymentConsistencyFinding::query()->lockForUpdate()->find($findingId);
            if (! $finding
                || $finding->state !== PaymentConsistencyFinding::STATE_OPEN
                || ! hash_equals((string) $finding->episode_uuid, $episodeUuid)
            ) {
                return false;
            }

            $alert = is_array($finding->alert_dispatch) ? $finding->alert_dispatch : [];
            $state = (string) ($alert['state'] ?? '');
            $nextAttemptAt = $alert['next_attempt_at'] ?? null;
            $staleQueued = $state === 'queued'
                && ! empty($alert['queued_at'])
                && now('UTC')->subMinutes(10)->gte((string) $alert['queued_at']);
            $staleSending = $state === 'sending'
                && ! empty($alert['claimed_at'])
                && now('UTC')->subMinutes(10)->gte((string) $alert['claimed_at']);
            if ($staleSending) {
                $alert['state'] = 'unknown';
                $alert['error_code'] = 'ALERT_DELIVERY_OUTCOME_UNKNOWN';
                $alert['unknown_at'] = now('UTC')->toIso8601String();
                $alert['next_attempt_at'] = null;
                unset($alert['claim_uuid'], $alert['claimed_at'], $alert['queued_at']);
                $finding->update(['alert_dispatch' => $alert]);
                $this->auditLog->log('payment_consistency.alert_unknown', null, $finding, [
                    'episode_uuid' => $episodeUuid,
                    'reason_code' => 'ALERT_DELIVERY_OUTCOME_UNKNOWN',
                ], (int) $finding->company_id);

                return false;
            }
            if (! in_array($state, ['pending', 'retryable'], true) && ! $staleQueued) {
                return false;
            }
            if ($nextAttemptAt && now('UTC')->lt((string) $nextAttemptAt)) {
                return false;
            }

            $alert['state'] = 'queued';
            $alert['queued_at'] = now('UTC')->toIso8601String();
            $alert['next_attempt_at'] = null;
            $finding->update(['alert_dispatch' => $alert]);

            return true;
        }, 3);
        if (! $queued) {
            return false;
        }

        try {
            SendPaymentConsistencyAlert::dispatch($findingId, $episodeUuid);

            return true;
        } catch (Throwable $exception) {
            DB::transaction(function () use ($findingId, $episodeUuid): void {
                $finding = PaymentConsistencyFinding::query()->lockForUpdate()->find($findingId);
                if (! $finding || ! hash_equals((string) $finding->episode_uuid, $episodeUuid)) {
                    return;
                }
                $alert = is_array($finding->alert_dispatch) ? $finding->alert_dispatch : [];
                if (($alert['state'] ?? null) !== 'queued') {
                    return;
                }
                $alert['state'] = 'retryable';
                $alert['error_code'] = 'ALERT_QUEUE_FAILED';
                $alert['next_attempt_at'] = now('UTC')->addMinutes(5)->toIso8601String();
                $finding->update(['alert_dispatch' => $alert]);
            }, 3);
            Log::warning('payment_consistency_alert_dispatch_failed', [
                'finding_id' => $findingId,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    public function recoverDue(int $companyId): int
    {
        $queued = 0;
        PaymentConsistencyFinding::query()
            ->where('company_id', $companyId)
            ->where('state', PaymentConsistencyFinding::STATE_OPEN)
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'episode_uuid'])
            ->each(function (PaymentConsistencyFinding $finding) use (&$queued): void {
                if ($this->queueFinding((int) $finding->id, (string) $finding->episode_uuid)) {
                    $queued++;
                }
            });

        return $queued;
    }
}

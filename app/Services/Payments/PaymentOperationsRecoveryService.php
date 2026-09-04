<?php

namespace App\Services\Payments;

use App\Jobs\RetrySkipCashPaymentProcessing;
use App\Models\AccountingAuditLog;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentProviderTransaction;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentOperationsRecoveryService
{
    public function __construct(
        private readonly PaymentOperationsAccessService $access,
        private readonly AccountingAuditLogService $auditLog,
        private readonly PaymentOperationsTrackingService $trackingService,
    ) {}

    /** @return array{operation_uuid:string,state:string} */
    public function retry(int $attemptId, string $operationUuid, User $actor): array
    {
        if (! Str::isUuid($operationUuid) || strtolower($operationUuid) !== $operationUuid) {
            throw ValidationException::withMessages(['operation_uuid' => __('A valid operation identifier is required.')]);
        }
        if (RateLimiter::tooManyAttempts($this->rateLimitKey($actor, $attemptId), 5)) {
            throw ValidationException::withMessages(['retry' => __('Too many retry requests. Please wait a minute.')]);
        }
        RateLimiter::hit($this->rateLimitKey($actor, $attemptId), 60);
        if (! Schema::hasTable('accounting_audit_logs')) {
            throw ValidationException::withMessages(['retry' => __('Payment recovery is unavailable because audit storage is unavailable.')]);
        }

        $dispatch = false;
        $result = DB::transaction(function () use ($attemptId, $operationUuid, $actor, &$dispatch): array {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            $this->access->assertCanRecover($actor, $attempt);
            $tracking = is_array($attempt->operations_tracking) ? $attempt->operations_tracking : [];
            $current = is_array($tracking['recovery'] ?? null) ? $tracking['recovery'] : [];
            if (($current['operation_uuid'] ?? null) === $operationUuid) {
                return ['operation_uuid' => $operationUuid, 'state' => (string) ($current['state'] ?? 'queued')];
            }
            $fingerprint = hash('sha256', 'payment_processing_retry|'.$attempt->id);
            $accepted = AccountingAuditLog::query()
                ->where('subject_type', PaymentCheckoutAttempt::class)
                ->where('subject_id', $attempt->id)
                ->where('action', 'payment.operations.recovery_accepted')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.operation_uuid')) = ?", [$operationUuid])
                ->latest('id')
                ->first();
            if ($accepted) {
                if (($accepted->payload['input_fingerprint'] ?? null) !== $fingerprint
                    || ($accepted->payload['kind'] ?? null) !== 'payment_processing_retry') {
                    throw ValidationException::withMessages(['operation_uuid' => __('This operation identifier was used for different input.')]);
                }
                $outcome = AccountingAuditLog::query()
                    ->where('subject_type', PaymentCheckoutAttempt::class)
                    ->where('subject_id', $attempt->id)
                    ->whereIn('action', [
                        'payment.operations.recovery_succeeded',
                        'payment.operations.recovery_blocked',
                        'payment.operations.recovery_failed',
                    ])
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.operation_uuid')) = ?", [$operationUuid])
                    ->latest('id')
                    ->first();
                $state = $outcome ? (string) str($outcome->action)->afterLast('_') : 'queued';

                return ['operation_uuid' => $operationUuid, 'state' => $state];
            }
            if (in_array((string) ($current['state'] ?? ''), ['queued', 'running'], true)) {
                throw ValidationException::withMessages(['retry' => __('Payment recovery is already in progress.')]);
            }

            $issue = $tracking['issues']['processing'] ?? null;
            $transactionId = (int) (($attempt->financial_intent ?? [])['provider_transaction_id'] ?? 0);
            $verifiedTransaction = $transactionId > 0 ? PaymentProviderTransaction::query()
                ->where('attempt_id', $attempt->id)
                ->whereKey($transactionId)
                ->whereNotNull('verified_paid_at')
                ->lockForUpdate()
                ->first() : null;
            if ($attempt->state !== 'paid_processing' || ! is_array($issue) || ! empty($issue['resolved_at']) || ! $verifiedTransaction) {
                throw ValidationException::withMessages(['retry' => __('This checkout has no eligible verified processing work to retry.')]);
            }

            $tracking['recovery'] = [
                'operation_uuid' => $operationUuid,
                'kind' => 'payment_processing_retry',
                'input_fingerprint' => $fingerprint,
                'state' => 'queued',
                'requested_by' => (int) $actor->id,
                'provider_transaction_id' => $transactionId,
                'queued_at' => now('UTC')->toIso8601String(),
            ];
            $attempt->update([
                'operations_tracking' => $tracking,
                'operations_next_action_at' => $this->trackingService->nextActionAt($tracking),
            ]);
            $this->auditLog->log('payment.operations.recovery_accepted', (int) $actor->id, $attempt, [
                'operation_uuid' => $operationUuid,
                'kind' => 'payment_processing_retry',
                'input_fingerprint' => $fingerprint,
                'provider_transaction_id' => $transactionId,
            ], (int) $attempt->company_id);
            $dispatch = true;

            return ['operation_uuid' => $operationUuid, 'state' => 'queued'];
        }, 3);

        if ($dispatch) {
            RetrySkipCashPaymentProcessing::dispatch($attemptId, $operationUuid);
        }

        return $result;
    }

    private function rateLimitKey(User $actor, int $attemptId): string
    {
        return 'payment-operations-retry:'.$actor->id.':'.$attemptId;
    }
}

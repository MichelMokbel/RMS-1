<?php

namespace App\Jobs;

use App\Models\PaymentCheckoutAttempt;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Payments\OrdinaryOrderActivationService;
use App\Services\Payments\PaymentCheckoutException;
use App\Services\Payments\PaymentOperationsAccessService;
use App\Services\Payments\PaymentOperationsTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetrySkipCashPaymentProcessing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $attemptId,
        public readonly string $operationUuid,
    ) {}

    public function handle(
        OrdinaryOrderActivationService $activation,
        PaymentOperationsAccessService $access,
        PaymentOperationsTrackingService $trackingService,
        AccountingAuditLogService $auditLog,
    ): void {
        $context = DB::transaction(function () use ($access, $trackingService): ?array {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($this->attemptId);
            $tracking = is_array($attempt?->operations_tracking) ? $attempt->operations_tracking : [];
            $recovery = is_array($tracking['recovery'] ?? null) ? $tracking['recovery'] : [];
            if (! $attempt || ($recovery['operation_uuid'] ?? null) !== $this->operationUuid
                || ($recovery['state'] ?? null) !== 'queued') {
                return null;
            }

            $actor = User::query()->find((int) ($recovery['requested_by'] ?? 0));
            if (! $actor) {
                return ['access_error' => 'RECOVERY_ACTOR_UNAVAILABLE'];
            }
            try {
                $access->assertCanRecover($actor, $attempt);
            } catch (\Throwable) {
                return ['access_error' => 'RECOVERY_PERMISSION_REVOKED'];
            }

            $recovery['state'] = 'running';
            $recovery['started_at'] = now('UTC')->toIso8601String();
            $tracking['recovery'] = $recovery;
            $attempt->update([
                'operations_tracking' => $tracking,
                'operations_next_action_at' => $trackingService->nextActionAt($tracking),
            ]);

            return [
                'provider_transaction_id' => (int) ($recovery['provider_transaction_id'] ?? 0),
                'actor_id' => (int) $actor->id,
            ];
        }, 3);
        if (! $context) {
            return;
        }
        if (isset($context['access_error'])) {
            $this->markFailure((string) $context['access_error'], 'blocked', $trackingService, $auditLog);

            return;
        }

        try {
            $activation->complete($this->attemptId, (int) $context['provider_transaction_id']);
        } catch (PaymentCheckoutException $exception) {
            $this->markFailure($exception->codeName, 'blocked', $trackingService, $auditLog);

            return;
        } catch (ValidationException) {
            $this->markFailure('FINANCIAL_PERIOD_BLOCKED', 'blocked', $trackingService, $auditLog);

            return;
        } catch (\Throwable) {
            $this->markFailure('PAYMENT_PROCESSING_FAILED', 'failed', $trackingService, $auditLog);

            return;
        }

        DB::transaction(function () use ($trackingService, $auditLog): void {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($this->attemptId);
            $tracking = is_array($attempt?->operations_tracking) ? $attempt->operations_tracking : [];
            $recovery = is_array($tracking['recovery'] ?? null) ? $tracking['recovery'] : [];
            if (! $attempt || ($recovery['operation_uuid'] ?? null) !== $this->operationUuid
                || ($recovery['state'] ?? null) !== 'running') {
                return;
            }
            $recovery['state'] = 'succeeded';
            $recovery['completed_at'] = now('UTC')->toIso8601String();
            $tracking['recovery'] = $recovery;
            $attempt->update([
                'operations_tracking' => $tracking,
                'operations_next_action_at' => $trackingService->nextActionAt($tracking),
            ]);
            $auditLog->log('payment.operations.recovery_succeeded', (int) ($recovery['requested_by'] ?? 0), $attempt, [
                'operation_uuid' => $this->operationUuid,
                'provider_transaction_id' => (int) ($recovery['provider_transaction_id'] ?? 0),
            ], (int) $attempt->company_id);
        }, 3);
        $trackingService->resolveProcessingIssue($this->attemptId);
    }

    private function markFailure(
        string $code,
        string $state,
        PaymentOperationsTrackingService $trackingService,
        AccountingAuditLogService $auditLog,
    ): void {
        DB::transaction(function () use ($code, $state, $trackingService, $auditLog): void {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($this->attemptId);
            $tracking = is_array($attempt?->operations_tracking) ? $attempt->operations_tracking : [];
            $recovery = is_array($tracking['recovery'] ?? null) ? $tracking['recovery'] : [];
            if (! $attempt || ($recovery['operation_uuid'] ?? null) !== $this->operationUuid
                || ! in_array((string) ($recovery['state'] ?? ''), ['queued', 'running'], true)) {
                return;
            }
            $recovery['state'] = $state;
            $recovery['error_code'] = $code;
            $recovery['completed_at'] = now('UTC')->toIso8601String();
            $tracking['recovery'] = $recovery;
            $attempt->update([
                'last_error_code' => $code,
                'operations_tracking' => $tracking,
                'operations_next_action_at' => $trackingService->nextActionAt($tracking),
            ]);
            $auditLog->log('payment.operations.recovery_'.$state, (int) ($recovery['requested_by'] ?? 0), $attempt, [
                'operation_uuid' => $this->operationUuid,
                'reason_code' => $code,
                'provider_transaction_id' => (int) ($recovery['provider_transaction_id'] ?? 0),
            ], (int) $attempt->company_id);
        }, 3);
        $trackingService->recordProcessingFailure($this->attemptId, $code);
    }
}

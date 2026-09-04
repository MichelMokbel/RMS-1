<?php

namespace App\Services\Payments;

use App\Jobs\ResendSkipCashOrderConfirmation;
use App\Models\AccountingAuditLog;
use App\Models\PaymentCheckoutAttempt;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentOperationsResendService
{
    public function __construct(
        private readonly PaymentOperationsAccessService $access,
        private readonly AccountingAuditLogService $auditLog,
        private readonly PaymentOperationsTrackingService $trackingService,
    ) {}

    public function snapshotHash(PaymentCheckoutAttempt $attempt): ?string
    {
        $snapshot = is_array($attempt->notification_snapshots) ? $attempt->notification_snapshots : [];
        if (empty($snapshot['customer_email']) || empty($snapshot['order_ids'])) {
            return null;
        }

        return hash('sha256', json_encode([
            'customer_email' => (string) $snapshot['customer_email'],
            'order_ids' => array_values(array_map('intval', (array) $snapshot['order_ids'])),
            'amount_cents' => (int) ($snapshot['amount_cents'] ?? 0),
            'reference' => (string) ($snapshot['reference'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array{operation_uuid:string,state:string} */
    public function resend(
        int $attemptId,
        string $operationUuid,
        string $snapshotHash,
        bool $acknowledgeUnknown,
        User $actor,
    ): array {
        if (! Str::isUuid($operationUuid) || strtolower($operationUuid) !== $operationUuid) {
            throw ValidationException::withMessages(['operation_uuid' => __('A valid operation identifier is required.')]);
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $snapshotHash)) {
            throw ValidationException::withMessages(['resend' => __('The saved confirmation changed. Reload and review it again.')]);
        }
        if (RateLimiter::tooManyAttempts($this->rateLimitKey($actor, $attemptId), 5)) {
            throw ValidationException::withMessages(['resend' => __('Too many resend requests. Please wait a minute.')]);
        }
        RateLimiter::hit($this->rateLimitKey($actor, $attemptId), 60);
        if (! Schema::hasTable('accounting_audit_logs')) {
            throw ValidationException::withMessages(['resend' => __('Confirmation resend is unavailable because audit storage is unavailable.')]);
        }

        $dispatch = false;
        $result = DB::transaction(function () use ($attemptId, $operationUuid, $snapshotHash, $acknowledgeUnknown, $actor, &$dispatch): array {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            $this->access->assertCanResend($actor, $attempt);
            $tracking = is_array($attempt->operations_tracking) ? $attempt->operations_tracking : [];
            $current = is_array($tracking['resend'] ?? null) ? $tracking['resend'] : [];
            if (($current['operation_uuid'] ?? null) === $operationUuid) {
                return ['operation_uuid' => $operationUuid, 'state' => (string) ($current['state'] ?? 'queued')];
            }

            $fingerprint = hash('sha256', 'customer_confirmation_resend|'.$attempt->id.'|'.$snapshotHash.'|'.(int) $acknowledgeUnknown);
            $accepted = AccountingAuditLog::query()
                ->where('subject_type', PaymentCheckoutAttempt::class)
                ->where('subject_id', $attempt->id)
                ->where('action', 'payment.operations.resend_accepted')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.operation_uuid')) = ?", [$operationUuid])
                ->latest('id')
                ->first();
            if ($accepted) {
                if (($accepted->payload['input_fingerprint'] ?? null) !== $fingerprint) {
                    throw ValidationException::withMessages(['operation_uuid' => __('This operation identifier was used for different input.')]);
                }
                $outcome = AccountingAuditLog::query()
                    ->where('subject_type', PaymentCheckoutAttempt::class)
                    ->where('subject_id', $attempt->id)
                    ->whereIn('action', [
                        'payment.operations.resend_sent',
                        'payment.operations.resend_blocked',
                        'payment.operations.resend_unknown',
                    ])
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.operation_uuid')) = ?", [$operationUuid])
                    ->latest('id')
                    ->first();

                return [
                    'operation_uuid' => $operationUuid,
                    'state' => $outcome ? (string) str($outcome->action)->afterLast('_') : 'queued',
                ];
            }
            if (in_array((string) ($current['state'] ?? ''), ['queued', 'sending'], true)) {
                throw ValidationException::withMessages(['resend' => __('A confirmation resend is already in progress.')]);
            }

            $this->assertEligible($attempt, $snapshotHash, $acknowledgeUnknown);
            $tracking['resend'] = [
                'operation_uuid' => $operationUuid,
                'kind' => 'customer_confirmation_resend',
                'input_fingerprint' => $fingerprint,
                'snapshot_hash' => $snapshotHash,
                'acknowledged_unknown' => $acknowledgeUnknown,
                'state' => 'queued',
                'requested_by' => (int) $actor->id,
                'queued_at' => now('UTC')->toIso8601String(),
            ];
            $attempt->update([
                'operations_tracking' => $tracking,
                'operations_next_action_at' => $this->trackingService->nextActionAt($tracking),
            ]);
            $this->auditLog->log('payment.operations.resend_accepted', (int) $actor->id, $attempt, [
                'operation_uuid' => $operationUuid,
                'kind' => 'customer_confirmation_resend',
                'input_fingerprint' => $fingerprint,
                'snapshot_hash' => $snapshotHash,
                'acknowledged_unknown' => $acknowledgeUnknown,
            ], (int) $attempt->company_id);
            $dispatch = true;

            return ['operation_uuid' => $operationUuid, 'state' => 'queued'];
        }, 3);

        if ($dispatch) {
            ResendSkipCashOrderConfirmation::dispatch($attemptId, $operationUuid);
        }

        return $result;
    }

    public function assertEligible(PaymentCheckoutAttempt $attempt, string $snapshotHash, bool $acknowledgeUnknown): void
    {
        $attempt->loadMissing(['targets.invoice']);
        if ($attempt->purpose !== 'ordinary_order' || $attempt->state !== 'completed') {
            throw ValidationException::withMessages(['resend' => __('Only a completed paid order confirmation can be resent.')]);
        }
        if (! hash_equals((string) ($this->snapshotHash($attempt) ?? ''), $snapshotHash)) {
            throw ValidationException::withMessages(['resend' => __('The saved confirmation changed. Reload and review it again.')]);
        }
        if ($attempt->targets->isEmpty() || $attempt->targets->contains(
            fn ($target): bool => ! $target->invoice || $target->invoice->status !== 'paid'
        )) {
            throw ValidationException::withMessages(['resend' => __('The current invoice state no longer supports this confirmation.')]);
        }
        $originalState = (string) data_get($attempt->notification_dispatch, 'customer_confirmation.state', '');
        if (! in_array($originalState, ['sent', 'failed', 'unknown'], true)) {
            throw ValidationException::withMessages(['resend' => __('The original customer confirmation is still being processed.')]);
        }
        if ($originalState === 'unknown' && ! $acknowledgeUnknown) {
            throw ValidationException::withMessages(['acknowledge_unknown' => __('Acknowledge that the original delivery may already have arrived.')]);
        }
    }

    private function rateLimitKey(User $actor, int $attemptId): string
    {
        return 'payment-operations-resend:'.$actor->id.':'.$attemptId;
    }
}

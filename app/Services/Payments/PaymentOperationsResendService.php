<?php

namespace App\Services\Payments;

use App\Jobs\ResendSkipCashOrderConfirmation;
use App\Jobs\SendSkipCashOrderConfirmation;
use App\Models\AccountingAuditLog;
use App\Models\PaymentCheckoutAttempt;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Mail\MailConfigurationUnavailableException;
use App\Services\Mail\MailSettingsService;
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
        private readonly MailSettingsService $mailSettings,
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

    /** @return array{operation_uuid:string,state:string} */
    public function retryAdminConfirmation(int $attemptId, string $operationUuid, User $actor): array
    {
        if (! Str::isUuid($operationUuid) || strtolower($operationUuid) !== $operationUuid) {
            throw ValidationException::withMessages(['admin_confirmation' => __('A valid operation identifier is required.')]);
        }
        if (RateLimiter::tooManyAttempts($this->adminConfirmationRateLimitKey($actor, $attemptId), 5)) {
            throw ValidationException::withMessages(['admin_confirmation' => __('Too many confirmation retry requests. Please wait a minute.')]);
        }
        RateLimiter::hit($this->adminConfirmationRateLimitKey($actor, $attemptId), 60);
        if (! Schema::hasTable('accounting_audit_logs')) {
            throw ValidationException::withMessages(['admin_confirmation' => __('Administrator confirmation retry is unavailable because audit storage is unavailable.')]);
        }

        $dispatch = false;
        $result = DB::transaction(function () use ($attemptId, $operationUuid, $actor, &$dispatch): array {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            $this->access->assertCanResend($actor, $attempt);

            $accepted = AccountingAuditLog::query()
                ->where('subject_type', PaymentCheckoutAttempt::class)
                ->where('subject_id', $attempt->id)
                ->where('action', 'payment.operations.admin_confirmation_requeued')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.operation_uuid')) = ?", [$operationUuid])
                ->first();
            if ($accepted) {
                return [
                    'operation_uuid' => $operationUuid,
                    'state' => (string) data_get($attempt->notification_dispatch, 'admin_confirmation.state', 'queued'),
                ];
            }

            $snapshot = is_array($attempt->notification_snapshots) ? $attempt->notification_snapshots : [];
            $dispatchState = is_array($attempt->notification_dispatch) ? $attempt->notification_dispatch : [];
            $adminDispatch = is_array($dispatchState['admin_confirmation'] ?? null)
                ? $dispatchState['admin_confirmation']
                : [];
            $existingRecipients = array_values(array_filter((array) ($snapshot['admin_emails'] ?? [])));
            if ($attempt->purpose !== 'ordinary_order' || $attempt->state !== 'completed'
                || ($adminDispatch['state'] ?? null) !== 'failed'
                || ($adminDispatch['error_code'] ?? null) !== 'ADMIN_RECIPIENT_MISSING'
                || $existingRecipients !== []) {
                throw ValidationException::withMessages([
                    'admin_confirmation' => __('Only an administrator confirmation that failed before obtaining a recipient can be retried here.'),
                ]);
            }

            try {
                $recipients = $this->mailSettings->adminRecipientsForCompany((int) $attempt->company_id);
            } catch (MailConfigurationUnavailableException) {
                throw ValidationException::withMessages([
                    'admin_confirmation' => __('The saved mail configuration is unavailable. Repair it in Mail Settings and try again.'),
                ]);
            }
            if ($recipients === []) {
                throw ValidationException::withMessages([
                    'admin_confirmation' => __('Add at least one administrator recipient in Mail Settings before retrying.'),
                ]);
            }

            $snapshot['admin_emails'] = $recipients;
            $dispatchState['admin_confirmation'] = [
                'state' => 'pending',
                'attempts' => max(1, (int) ($adminDispatch['attempts'] ?? 0)),
                'requeued_at' => now('UTC')->toIso8601String(),
            ];
            $attempt->update([
                'notification_snapshots' => $snapshot,
                'notification_dispatch' => $dispatchState,
            ]);
            $this->auditLog->log('payment.operations.admin_confirmation_requeued', (int) $actor->id, $attempt, [
                'operation_uuid' => $operationUuid,
                'previous_error_code' => 'ADMIN_RECIPIENT_MISSING',
                'recipient_count' => count($recipients),
            ], (int) $attempt->company_id);
            $dispatch = true;

            return ['operation_uuid' => $operationUuid, 'state' => 'queued'];
        }, 3);

        if ($dispatch) {
            SendSkipCashOrderConfirmation::dispatch($attemptId, 'admin');
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

    private function adminConfirmationRateLimitKey(User $actor, int $attemptId): string
    {
        return 'payment-operations-admin-confirmation:'.$actor->id.':'.$attemptId;
    }
}

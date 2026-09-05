<?php

namespace App\Jobs;

use App\Mail\DailyDishOrderCustomerMail;
use App\Models\Order;
use App\Models\PaymentCheckoutAttempt;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Mail\EmailLogService;
use App\Services\Mail\MailConfigurationUnavailableException;
use App\Services\Mail\MailSettingsService;
use App\Services\Payments\PaymentOperationsAccessService;
use App\Services\Payments\PaymentOperationsResendService;
use App\Services\Payments\PaymentOperationsTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ResendSkipCashOrderConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $attemptId,
        public readonly string $operationUuid,
    ) {}

    public function handle(
        PaymentOperationsResendService $resends,
        PaymentOperationsAccessService $access,
        PaymentOperationsTrackingService $trackingService,
        AccountingAuditLogService $auditLog,
        EmailLogService $emailLogs,
        MailSettingsService $mailSettings,
    ): void {
        $context = DB::transaction(function () use ($resends, $access, $trackingService): ?array {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($this->attemptId);
            $tracking = is_array($attempt?->operations_tracking) ? $attempt->operations_tracking : [];
            $resend = is_array($tracking['resend'] ?? null) ? $tracking['resend'] : [];
            if (! $attempt || ($resend['operation_uuid'] ?? null) !== $this->operationUuid
                || ($resend['state'] ?? null) !== 'queued') {
                return null;
            }

            $actor = User::query()->find((int) ($resend['requested_by'] ?? 0));
            try {
                if (! $actor) {
                    throw new \RuntimeException('Actor unavailable.');
                }
                $access->assertCanResend($actor, $attempt);
                $resends->assertEligible(
                    $attempt,
                    (string) ($resend['snapshot_hash'] ?? ''),
                    (bool) ($resend['acknowledged_unknown'] ?? false),
                );
            } catch (\Throwable) {
                return ['access_error' => $actor ? 'RESEND_NO_LONGER_ELIGIBLE' : 'RESEND_ACTOR_UNAVAILABLE'];
            }

            $snapshot = is_array($attempt->notification_snapshots) ? $attempt->notification_snapshots : [];
            $resend['state'] = 'sending';
            $resend['started_at'] = now('UTC')->toIso8601String();
            $resend['attempts'] = max(0, (int) ($resend['attempts'] ?? 0)) + 1;
            $tracking['resend'] = $resend;
            $attempt->update([
                'operations_tracking' => $tracking,
                'operations_next_action_at' => $trackingService->nextActionAt($tracking),
            ]);

            return [
                'recipient' => (string) ($snapshot['customer_email'] ?? ''),
                'order_ids' => array_values(array_map('intval', (array) ($snapshot['order_ids'] ?? []))),
                'portal_user_id' => (int) $attempt->portal_user_id,
            ];
        }, 3);
        if (! $context) {
            return;
        }
        if (isset($context['access_error'])) {
            $this->markOutcome('blocked', (string) $context['access_error'], null, $trackingService, $auditLog);

            return;
        }

        $orders = Order::query()->whereIn('id', $context['order_ids'])->orderBy('scheduled_date')->get();
        if ($orders->isEmpty() || $context['recipient'] === '') {
            $this->markOutcome('blocked', 'RESEND_SNAPSHOT_UNAVAILABLE', null, $trackingService, $auditLog);

            return;
        }

        $mail = new DailyDishOrderCustomerMail($orders, null, null);
        try {
            $mailSettings->prepareForDelivery();
            $mailer = (string) config('mail.default', 'log');
            if (in_array($mailer, ['log', 'array'], true)) {
                $emailLog = $emailLogs->log(
                    'skipcash_order_confirmation_resend', 'customer', 'skipped', $mail, [$context['recipient']],
                    userId: $context['portal_user_id'], orderId: $orders->first()->id, mailer: $mailer,
                    context: [
                        'checkout_attempt_id' => $this->attemptId,
                        'operation_uuid' => $this->operationUuid,
                        'notification_kind' => 'customer_confirmation_resend',
                        'reason' => 'mail_delivery_disabled',
                    ],
                );
            } else {
                Mail::to([$context['recipient']])->send($mail);
                $emailLog = $emailLogs->log(
                    'skipcash_order_confirmation_resend', 'customer', 'sent', $mail, [$context['recipient']],
                    userId: $context['portal_user_id'], orderId: $orders->first()->id, mailer: $mailer,
                    context: [
                        'checkout_attempt_id' => $this->attemptId,
                        'operation_uuid' => $this->operationUuid,
                        'notification_kind' => 'customer_confirmation_resend',
                    ],
                );
            }
        } catch (MailConfigurationUnavailableException) {
            $this->markOutcome('blocked', 'MAIL_CONFIGURATION_UNAVAILABLE', null, $trackingService, $auditLog);

            return;
        } catch (\Throwable) {
            $this->markOutcome('unknown', 'EMAIL_DELIVERY_UNKNOWN', null, $trackingService, $auditLog);

            return;
        }

        $this->markOutcome('sent', null, (int) $emailLog->id, $trackingService, $auditLog);
        $trackingService->resolveConfirmationIssue($this->attemptId, 'customer');
    }

    private function markOutcome(
        string $state,
        ?string $errorCode,
        ?int $emailLogId,
        PaymentOperationsTrackingService $trackingService,
        AccountingAuditLogService $auditLog,
    ): void {
        DB::transaction(function () use ($state, $errorCode, $emailLogId, $trackingService, $auditLog): void {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($this->attemptId);
            $tracking = is_array($attempt?->operations_tracking) ? $attempt->operations_tracking : [];
            $resend = is_array($tracking['resend'] ?? null) ? $tracking['resend'] : [];
            if (! $attempt || ($resend['operation_uuid'] ?? null) !== $this->operationUuid
                || ! in_array((string) ($resend['state'] ?? ''), ['queued', 'sending'], true)) {
                return;
            }
            $resend['state'] = $state;
            $resend['completed_at'] = now('UTC')->toIso8601String();
            $resend['error_code'] = $errorCode;
            $resend['email_log_id'] = $emailLogId;
            $tracking['resend'] = $resend;
            $attempt->update([
                'operations_tracking' => $tracking,
                'operations_next_action_at' => $trackingService->nextActionAt($tracking),
            ]);
            $auditLog->log('payment.operations.resend_'.$state, (int) ($resend['requested_by'] ?? 0), $attempt, [
                'operation_uuid' => $this->operationUuid,
                'reason_code' => $errorCode,
                'email_log_id' => $emailLogId,
            ], (int) $attempt->company_id);
        }, 3);
    }
}

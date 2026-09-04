<?php

namespace App\Jobs;

use App\Mail\PaymentOperationsAlertMail;
use App\Models\PaymentCheckoutAttempt;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Mail\EmailLogService;
use App\Services\Payments\PaymentOperationsTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendPaymentOperationsAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $attemptId,
        public readonly string $episodeUuid,
    ) {}

    public function handle(
        EmailLogService $emailLogs,
        PaymentOperationsTrackingService $trackingService,
        AccountingAuditLogService $auditLog,
    ): void {
        $context = DB::transaction(function () use ($trackingService): ?array {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($this->attemptId);
            $tracking = is_array($attempt?->operations_tracking) ? $attempt->operations_tracking : [];
            $issue = $tracking['issues']['processing'] ?? null;
            if (! $attempt || ! is_array($issue) || ($issue['episode_uuid'] ?? null) !== $this->episodeUuid) {
                return null;
            }
            if (! empty($issue['resolved_at'])) {
                $issue['alert']['state'] = 'suppressed';
                $issue['alert']['suppressed_at'] = now('UTC')->toIso8601String();
                $issue['alert']['next_attempt_at'] = null;
                $tracking['issues']['processing'] = $issue;
                $attempt->update([
                    'operations_tracking' => $tracking,
                    'operations_next_action_at' => $trackingService->nextActionAt($tracking),
                ]);

                return null;
            }

            $alert = is_array($issue['alert'] ?? null) ? $issue['alert'] : [];
            $state = (string) ($alert['state'] ?? '');
            $nextAttemptAt = $alert['next_attempt_at'] ?? null;
            if (! in_array($state, ['pending', 'retryable'], true)
                || $nextAttemptAt && now('UTC')->lt($nextAttemptAt)) {
                return null;
            }

            $snapshots = is_array($attempt->notification_snapshots) ? $attempt->notification_snapshots : [];
            $snapshot = $snapshots['operations_alerts'][$this->episodeUuid] ?? null;
            if (! is_array($snapshot)) {
                return ['missing_snapshot' => true];
            }

            $alert['state'] = 'sending';
            $alert['claim_uuid'] = (string) \Illuminate\Support\Str::uuid();
            $alert['claimed_at'] = now('UTC')->toIso8601String();
            $alert['attempts'] = max(0, (int) ($alert['attempts'] ?? 0)) + 1;
            $alert['next_attempt_at'] = null;
            $issue['alert'] = $alert;
            $tracking['issues']['processing'] = $issue;
            $attempt->update([
                'operations_tracking' => $tracking,
                'operations_next_action_at' => $trackingService->nextActionAt($tracking),
            ]);

            return [
                'snapshot' => $snapshot,
                'portal_user_id' => $attempt->portal_user_id,
            ];
        }, 3);
        if (! $context) {
            return;
        }
        if (! empty($context['missing_snapshot'])) {
            $this->markFailed('ALERT_SNAPSHOT_MISSING', false, $trackingService, $auditLog);

            return;
        }

        $snapshot = $context['snapshot'];
        $recipients = array_values(array_filter((array) ($snapshot['admin_emails'] ?? [])));
        if ($recipients === []) {
            $this->markFailed('ADMIN_RECIPIENT_MISSING', false, $trackingService, $auditLog);

            return;
        }

        $mail = new PaymentOperationsAlertMail(
            (string) ($snapshot['reference'] ?? ''),
            (string) ($snapshot['reason_code'] ?? 'PAYMENT_PROCESSING_FAILED'),
            (string) ($snapshot['url'] ?? ''),
        );
        $mailer = (string) config('mail.default', 'log');

        try {
            if (in_array($mailer, ['log', 'array'], true)) {
                $emailLog = $emailLogs->log(
                    'payment_operations_alert', 'admin', 'skipped', $mail, $recipients,
                    userId: (int) $context['portal_user_id'], mailer: $mailer,
                    context: [
                        'checkout_attempt_id' => $this->attemptId,
                        'issue_episode_uuid' => $this->episodeUuid,
                        'notification_kind' => 'admin_issue_alert',
                        'reason' => 'mail_delivery_disabled',
                    ],
                );
            } else {
                Mail::to($recipients)->send($mail);
                $emailLog = $emailLogs->log(
                    'payment_operations_alert', 'admin', 'sent', $mail, $recipients,
                    userId: (int) $context['portal_user_id'], mailer: $mailer,
                    context: [
                        'checkout_attempt_id' => $this->attemptId,
                        'issue_episode_uuid' => $this->episodeUuid,
                        'notification_kind' => 'admin_issue_alert',
                    ],
                );
            }
        } catch (\Throwable) {
            $this->markFailed('EMAIL_SEND_FAILED', true, $trackingService, $auditLog);

            return;
        }

        DB::transaction(function () use ($emailLog, $trackingService, $auditLog): void {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($this->attemptId);
            $tracking = is_array($attempt?->operations_tracking) ? $attempt->operations_tracking : [];
            $issue = $tracking['issues']['processing'] ?? null;
            if (! $attempt || ! is_array($issue) || ($issue['episode_uuid'] ?? null) !== $this->episodeUuid) {
                return;
            }
            $alert = is_array($issue['alert'] ?? null) ? $issue['alert'] : [];
            if (($alert['state'] ?? null) !== 'sending') {
                return;
            }
            $alert['state'] = 'sent';
            $alert['sent_at'] = now('UTC')->toIso8601String();
            $alert['email_log_id'] = (int) $emailLog->id;
            unset($alert['claim_uuid'], $alert['claimed_at']);
            $issue['alert'] = $alert;
            $tracking['issues']['processing'] = $issue;
            $attempt->update([
                'operations_tracking' => $tracking,
                'operations_next_action_at' => $trackingService->nextActionAt($tracking),
            ]);
            $auditLog->log('payment.operations.alert_sent', null, $attempt, [
                'episode_uuid' => $this->episodeUuid,
                'email_log_id' => (int) $emailLog->id,
            ], (int) $attempt->company_id);
        }, 3);
    }

    private function markFailed(
        string $code,
        bool $canRetry,
        PaymentOperationsTrackingService $trackingService,
        AccountingAuditLogService $auditLog,
    ): void {
        DB::transaction(function () use ($code, $canRetry, $trackingService, $auditLog): void {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($this->attemptId);
            $tracking = is_array($attempt?->operations_tracking) ? $attempt->operations_tracking : [];
            $issue = $tracking['issues']['processing'] ?? null;
            if (! $attempt || ! is_array($issue) || ($issue['episode_uuid'] ?? null) !== $this->episodeUuid) {
                return;
            }
            $alert = is_array($issue['alert'] ?? null) ? $issue['alert'] : [];
            $attempts = max(1, (int) ($alert['attempts'] ?? 0));
            $retryable = $canRetry && $attempts < 5;
            $delays = [1, 5, 15, 60];
            $alert['state'] = $retryable ? 'retryable' : 'failed';
            $alert['error_code'] = $code;
            $alert['failed_at'] = now('UTC')->toIso8601String();
            $alert['next_attempt_at'] = $retryable
                ? now('UTC')->addMinutes($delays[min($attempts - 1, count($delays) - 1)])->toIso8601String()
                : null;
            unset($alert['claim_uuid'], $alert['claimed_at']);
            $issue['alert'] = $alert;
            $tracking['issues']['processing'] = $issue;
            $attempt->update([
                'operations_tracking' => $tracking,
                'operations_next_action_at' => $trackingService->nextActionAt($tracking),
            ]);
            $auditLog->log('payment.operations.alert_'.$alert['state'], null, $attempt, [
                'episode_uuid' => $this->episodeUuid,
                'reason_code' => $code,
            ], (int) $attempt->company_id);
        }, 3);
    }
}

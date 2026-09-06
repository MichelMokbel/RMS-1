<?php

namespace App\Jobs;

use App\Mail\PaymentConsistencyAlertMail;
use App\Models\PaymentConsistencyFinding;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Mail\EmailLogService;
use App\Services\Mail\MailConfigurationUnavailableException;
use App\Services\Mail\MailSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendPaymentConsistencyAlert implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $findingId,
        public readonly string $episodeUuid,
    ) {}

    public function uniqueId(): string
    {
        return $this->findingId.':'.$this->episodeUuid;
    }

    public function handle(
        EmailLogService $emailLogs,
        MailSettingsService $mailSettings,
        AccountingAuditLogService $auditLog,
    ): void {
        $context = DB::transaction(function (): ?array {
            $finding = PaymentConsistencyFinding::query()->lockForUpdate()->find($this->findingId);
            if (! $finding
                || $finding->state !== PaymentConsistencyFinding::STATE_OPEN
                || ! hash_equals((string) $finding->episode_uuid, $this->episodeUuid)
            ) {
                return null;
            }

            $alert = is_array($finding->alert_dispatch) ? $finding->alert_dispatch : [];
            if (! in_array((string) ($alert['state'] ?? ''), ['pending', 'queued', 'retryable'], true)) {
                return null;
            }
            if (! empty($alert['next_attempt_at']) && now('UTC')->lt((string) $alert['next_attempt_at'])) {
                return null;
            }

            $alert['state'] = 'sending';
            $alert['attempts'] = max(0, (int) ($alert['attempts'] ?? 0)) + 1;
            $alert['claim_uuid'] = (string) Str::uuid();
            $alert['claimed_at'] = now('UTC')->toIso8601String();
            $alert['next_attempt_at'] = null;
            unset($alert['queued_at']);
            $finding->update(['alert_dispatch' => $alert]);

            return [
                'company_id' => (int) $finding->company_id,
                'rule_code' => (string) $finding->rule_code,
                'subject_type' => (string) $finding->subject_type,
                'subject_id' => (int) $finding->subject_id,
                'issue_codes' => collect((array) data_get($finding->observed, 'issues', []))
                    ->pluck('code')->filter()->map(fn ($code): string => (string) $code)->unique()->values()->all(),
            ];
        }, 3);
        if (! $context) {
            return;
        }

        $recipients = $mailSettings->adminRecipientsForCompany($context['company_id']);
        if ($recipients === []) {
            $this->markFailed('ADMIN_RECIPIENT_MISSING', false, $auditLog);

            return;
        }

        $mail = new PaymentConsistencyAlertMail(
            $context['rule_code'],
            $context['subject_type'],
            $context['subject_id'],
            $this->episodeUuid,
            $context['issue_codes'],
            route('receivables.payments.consistency.show', $this->findingId),
        );

        try {
            $mailSettings->prepareForDelivery();
        } catch (MailConfigurationUnavailableException) {
            $this->markFailed('MAIL_CONFIGURATION_UNAVAILABLE', true, $auditLog);

            return;
        }

        $mailer = (string) config('mail.default', 'log');
        if (in_array($mailer, ['log', 'array'], true)) {
            try {
                $emailLog = $emailLogs->log(
                    'payment_consistency_alert', 'admin', 'skipped', $mail, $recipients,
                    mailer: $mailer,
                    context: $this->emailContext('mail_delivery_disabled'),
                );
            } catch (\Throwable) {
                $this->markFailed('EMAIL_LOG_FAILED_BEFORE_DELIVERY', true, $auditLog);

                return;
            }
        } else {
            try {
                Mail::to($recipients)->send($mail);
            } catch (\Throwable) {
                $this->markUnknown('EMAIL_DELIVERY_OUTCOME_UNKNOWN', $auditLog);

                return;
            }

            try {
                $emailLog = $emailLogs->log(
                    'payment_consistency_alert', 'admin', 'sent', $mail, $recipients,
                    mailer: $mailer,
                    context: $this->emailContext(),
                );
            } catch (\Throwable) {
                $this->markUnknown('EMAIL_LOG_FAILED_AFTER_DELIVERY', $auditLog);

                return;
            }
        }

        DB::transaction(function () use ($emailLog, $auditLog): void {
            $finding = PaymentConsistencyFinding::query()->lockForUpdate()->find($this->findingId);
            if (! $finding || ! hash_equals((string) $finding->episode_uuid, $this->episodeUuid)) {
                return;
            }
            $alert = is_array($finding->alert_dispatch) ? $finding->alert_dispatch : [];
            if (($alert['state'] ?? null) !== 'sending') {
                return;
            }
            $alert['state'] = 'sent';
            $alert['sent_at'] = now('UTC')->toIso8601String();
            $alert['email_log_id'] = (int) $emailLog->id;
            unset($alert['claim_uuid'], $alert['claimed_at']);
            $finding->update(['alert_dispatch' => $alert]);
            $auditLog->log('payment_consistency.alert_sent', null, $finding, [
                'episode_uuid' => $this->episodeUuid,
                'email_log_id' => (int) $emailLog->id,
            ], (int) $finding->company_id);
        }, 3);
    }

    private function markFailed(string $code, bool $canRetry, AccountingAuditLogService $auditLog): void
    {
        DB::transaction(function () use ($code, $canRetry, $auditLog): void {
            $finding = PaymentConsistencyFinding::query()->lockForUpdate()->find($this->findingId);
            if (! $finding || ! hash_equals((string) $finding->episode_uuid, $this->episodeUuid)) {
                return;
            }
            $alert = is_array($finding->alert_dispatch) ? $finding->alert_dispatch : [];
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
            $finding->update(['alert_dispatch' => $alert]);
            $auditLog->log('payment_consistency.alert_'.$alert['state'], null, $finding, [
                'episode_uuid' => $this->episodeUuid,
                'reason_code' => $code,
            ], (int) $finding->company_id);
        }, 3);
    }

    private function markUnknown(string $code, AccountingAuditLogService $auditLog): void
    {
        DB::transaction(function () use ($code, $auditLog): void {
            $finding = PaymentConsistencyFinding::query()->lockForUpdate()->find($this->findingId);
            if (! $finding || ! hash_equals((string) $finding->episode_uuid, $this->episodeUuid)) {
                return;
            }
            $alert = is_array($finding->alert_dispatch) ? $finding->alert_dispatch : [];
            $alert['state'] = 'unknown';
            $alert['error_code'] = $code;
            $alert['unknown_at'] = now('UTC')->toIso8601String();
            $alert['next_attempt_at'] = null;
            unset($alert['claim_uuid'], $alert['claimed_at'], $alert['queued_at']);
            $finding->update(['alert_dispatch' => $alert]);
            $auditLog->log('payment_consistency.alert_unknown', null, $finding, [
                'episode_uuid' => $this->episodeUuid,
                'reason_code' => $code,
            ], (int) $finding->company_id);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function emailContext(?string $reason = null): array
    {
        return array_filter([
            'payment_consistency_finding_id' => $this->findingId,
            'issue_episode_uuid' => $this->episodeUuid,
            'notification_kind' => 'admin_consistency_alert',
            'reason' => $reason,
        ], fn ($value): bool => $value !== null);
    }
}

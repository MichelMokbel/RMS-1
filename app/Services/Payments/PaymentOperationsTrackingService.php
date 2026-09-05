<?php

namespace App\Services\Payments;

use App\Jobs\ResendSkipCashOrderConfirmation;
use App\Jobs\RetrySkipCashPaymentProcessing;
use App\Jobs\SendPaymentOperationsAlert;
use App\Models\PaymentCheckoutAttempt;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Mail\MailConfigurationUnavailableException;
use App\Services\Mail\MailSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentOperationsTrackingService
{
    /** @var array<int, string> */
    public const ISSUE_SLOTS = [
        'processing',
        'provider_evidence',
        'customer_confirmation',
        'admin_confirmation',
    ];

    /** @var array<int, string> */
    private const IMMEDIATE_PROCESSING_CODES = [
        'FINANCIAL_PERIOD_BLOCKED',
        'PAYMENT_SOURCE_MISMATCH',
        'CLEARING_ACCOUNT_MISSING',
        'TARGET_TOTAL_MISMATCH',
        'SYSTEM_ACTOR_MISSING',
        'FINANCIAL_DATE_MISSING',
        'TARGET_ALREADY_LINKED',
        'TARGET_SNAPSHOT_INVALID',
        'PAYMENT_ALLOCATION_INCOMPLETE',
    ];

    public function __construct(
        private readonly AccountingAuditLogService $auditLog,
        private readonly AccountingContextService $accountingContext,
        private readonly MailSettingsService $mailSettings,
    ) {}

    public function recordProcessingFailure(int $attemptId, string $reasonCode): void
    {
        $attempt = PaymentCheckoutAttempt::query()->select(['id', 'state'])->find($attemptId);
        if (! $attempt || $attempt->state !== 'paid_processing') {
            return;
        }

        $this->recordIssue(
            $attemptId,
            'processing',
            $reasonCode,
            in_array($reasonCode, self::IMMEDIATE_PROCESSING_CODES, true) ? 0 : 15,
        );
    }

    public function recordProviderEvidenceIssue(int $attemptId, string $reasonCode): void
    {
        $this->recordIssue($attemptId, 'provider_evidence', $reasonCode, 0);
    }

    public function recordConfirmationFailure(int $attemptId, string $audience, string $reasonCode): void
    {
        $slot = match ($audience) {
            'customer' => 'customer_confirmation',
            'admin' => 'admin_confirmation',
            default => null,
        };
        if ($slot) {
            $this->recordIssue($attemptId, $slot, $reasonCode, 0);
        }
    }

    public function recordConsistencyIssue(int $attemptId, string $reasonCode): void
    {
        $this->recordIssue($attemptId, 'processing', $reasonCode, 0, false);
    }

    public function resolveConsistencyIssue(int $attemptId): void
    {
        $this->resolveIssue($attemptId, 'processing', 'CONSISTENCY_');
    }

    public function resolveProcessingIssue(int $attemptId): void
    {
        $this->resolveIssue($attemptId, 'processing');
    }

    public function resolveProviderEvidenceIssue(int $attemptId): void
    {
        $this->resolveIssue($attemptId, 'provider_evidence');
    }

    public function resolveConfirmationIssue(int $attemptId, string $audience): void
    {
        $slot = match ($audience) {
            'customer' => 'customer_confirmation',
            'admin' => 'admin_confirmation',
            default => null,
        };
        if ($slot) {
            $this->resolveIssue($attemptId, $slot);
        }
    }

    public function observeOutstanding(int $limit = 100): int
    {
        $limit = max(1, min(100, $limit));
        $attempts = PaymentCheckoutAttempt::query()
            ->where(function ($query): void {
                $query->where(function ($processing): void {
                    $processing->where('state', 'paid_processing')->whereNotNull('last_error_code');
                })->orWhere('provider_create_outcome', 'unknown')
                    ->orWhere(function ($due): void {
                        $due->whereNotNull('operations_next_action_at')
                            ->where('operations_next_action_at', '<=', now('UTC'));
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'state', 'provider_create_outcome', 'last_error_code', 'operations_tracking', 'operations_next_action_at']);
        foreach ($attempts as $attempt) {
            if ($attempt->state === 'paid_processing' && $attempt->last_error_code) {
                $this->recordProcessingFailure((int) $attempt->id, (string) $attempt->last_error_code);
            }
            if ($attempt->provider_create_outcome === 'unknown') {
                $this->recordProviderEvidenceIssue((int) $attempt->id, 'PROVIDER_CREATE_UNKNOWN');
            }
            $attempt->refresh();
            if (! $attempt->operations_next_action_at?->isPast()) {
                continue;
            }
            $tracking = is_array($attempt->operations_tracking) ? $attempt->operations_tracking : [];
            foreach ((array) ($tracking['issues'] ?? []) as $slot => $issue) {
                $alert = is_array($issue) ? ($issue['alert'] ?? null) : null;
                $alertState = (string) ($alert['state'] ?? '');
                $alertDue = $alertState === 'retryable'
                    || $alertState === 'pending' && (
                        empty($alert['queued_at'])
                        || CarbonImmutable::parse((string) $alert['queued_at'])->addSeconds(120)->isPast()
                    );
                if (in_array($slot, self::ISSUE_SLOTS, true)
                    && is_array($issue) && empty($issue['resolved_at']) && is_array($alert)
                    && $alertDue) {
                    SendPaymentOperationsAlert::dispatch((int) $attempt->id, $slot, (string) $issue['episode_uuid']);
                }
            }
            $this->recoverQueuedAction($attempt);
        }

        return $attempts->count();
    }

    /** @param array<string, mixed> $tracking */
    public function nextActionAt(array $tracking): ?CarbonImmutable
    {
        $dates = [];
        foreach ((array) ($tracking['issues'] ?? []) as $issue) {
            if (! is_array($issue) || ! empty($issue['resolved_at'])) {
                continue;
            }
            $alert = is_array($issue['alert'] ?? null) ? $issue['alert'] : null;
            if (! $alert) {
                $dates[] = CarbonImmutable::parse((string) $issue['attention_at']);
            } elseif (($alert['state'] ?? null) === 'pending') {
                $dates[] = CarbonImmutable::parse((string) ($alert['queued_at'] ?? now('UTC')))->addSeconds(120);
            } elseif (($alert['state'] ?? null) === 'retryable') {
                $dates[] = CarbonImmutable::parse((string) ($alert['next_attempt_at'] ?? now('UTC')));
            }
        }

        $recovery = is_array($tracking['recovery'] ?? null) ? $tracking['recovery'] : null;
        if ($recovery && ($recovery['state'] ?? null) === 'queued') {
            $dates[] = CarbonImmutable::parse((string) ($recovery['queued_at'] ?? now('UTC')))->addSeconds(120);
        } elseif ($recovery && ($recovery['state'] ?? null) === 'running' && ! empty($recovery['started_at'])) {
            $dates[] = CarbonImmutable::parse((string) $recovery['started_at'])->addSeconds(120);
        }

        $resend = is_array($tracking['resend'] ?? null) ? $tracking['resend'] : null;
        if ($resend && ($resend['state'] ?? null) === 'queued') {
            $dates[] = CarbonImmutable::parse((string) ($resend['queued_at'] ?? now('UTC')))->addSeconds(120);
        } elseif ($resend && ($resend['state'] ?? null) === 'sending' && ! empty($resend['started_at'])) {
            $dates[] = CarbonImmutable::parse((string) $resend['started_at'])->addSeconds(120);
        }

        return $dates === [] ? null : collect($dates)->sort()->first();
    }

    private function recordIssue(
        int $attemptId,
        string $slot,
        string $reasonCode,
        int $attentionDelayMinutes,
        bool $replaceOpenReason = true,
    ): void {
        $dispatch = false;
        $episodeUuid = null;

        DB::transaction(function () use ($attemptId, $slot, $reasonCode, $attentionDelayMinutes, $replaceOpenReason, &$dispatch, &$episodeUuid): void {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($attemptId);
            if (! $attempt || ! in_array($slot, self::ISSUE_SLOTS, true)) {
                return;
            }

            $now = CarbonImmutable::now('UTC');
            $tracking = is_array($attempt->operations_tracking) ? $attempt->operations_tracking : [];
            $issues = is_array($tracking['issues'] ?? null) ? $tracking['issues'] : [];
            $issue = is_array($issues[$slot] ?? null) ? $issues[$slot] : [];
            $isOpen = $issue !== [] && empty($issue['resolved_at']);
            $opened = ! $isOpen;
            $oldReason = $isOpen ? (string) ($issue['reason_code'] ?? '') : null;
            if ($isOpen && ! $replaceOpenReason) {
                return;
            }

            if ($opened) {
                $episodeUuid = (string) Str::uuid();
                $issue = [
                    'episode_uuid' => $episodeUuid,
                    'reason_code' => $this->boundedReason($reasonCode),
                    'first_seen_at' => $now->toIso8601String(),
                    'last_seen_at' => $now->toIso8601String(),
                    'attention_at' => $now->addMinutes(max(0, $attentionDelayMinutes))->toIso8601String(),
                    'resolved_at' => null,
                ];
            } else {
                $episodeUuid = (string) $issue['episode_uuid'];
                $issue['reason_code'] = $this->boundedReason($reasonCode);
                $issue['last_seen_at'] = $now->toIso8601String();
                $earlierAttention = $now->addMinutes(max(0, $attentionDelayMinutes));
                if ($earlierAttention->lt(CarbonImmutable::parse((string) $issue['attention_at']))) {
                    $issue['attention_at'] = $earlierAttention->toIso8601String();
                }
            }

            if ($now->greaterThanOrEqualTo(CarbonImmutable::parse((string) $issue['attention_at']))
                && ! is_array($issue['alert'] ?? null)) {
                $issue['alert'] = [
                    'episode_uuid' => $episodeUuid,
                    'kind' => 'admin_issue_alert',
                    'state' => 'pending',
                    'attempts' => 0,
                    'queued_at' => $now->toIso8601String(),
                    'next_attempt_at' => null,
                ];
                $this->storeAlertSnapshot($attempt, $episodeUuid, $slot, (string) $issue['reason_code']);
                $dispatch = true;
            }

            $issues[$slot] = $issue;
            $tracking['issues'] = $issues;
            $attempt->update([
                'operations_tracking' => $tracking,
                'operations_next_action_at' => $this->nextActionAt($tracking),
            ]);

            if ($opened) {
                $this->auditLog->log('payment.operations.issue_opened', null, $attempt, [
                    'slot' => $slot,
                    'episode_uuid' => $episodeUuid,
                    'reason_code' => $issue['reason_code'],
                    'attention_at' => $issue['attention_at'],
                ], (int) $attempt->company_id);
            } elseif ($oldReason !== $issue['reason_code']) {
                $this->auditLog->log('payment.operations.issue_updated', null, $attempt, [
                    'slot' => $slot,
                    'episode_uuid' => $episodeUuid,
                    'before_reason_code' => $oldReason,
                    'reason_code' => $issue['reason_code'],
                    'attention_at' => $issue['attention_at'],
                ], (int) $attempt->company_id);
            }
            if ($dispatch) {
                $this->auditLog->log('payment.operations.alert_intended', null, $attempt, [
                    'slot' => $slot,
                    'episode_uuid' => $episodeUuid,
                    'reason_code' => $issue['reason_code'],
                ], (int) $attempt->company_id);
            }
        }, 3);

        if ($dispatch && $episodeUuid) {
            SendPaymentOperationsAlert::dispatch($attemptId, $slot, $episodeUuid);
        }
    }

    private function resolveIssue(int $attemptId, string $slot, ?string $reasonPrefix = null): void
    {
        DB::transaction(function () use ($attemptId, $slot, $reasonPrefix): void {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($attemptId);
            $tracking = is_array($attempt?->operations_tracking) ? $attempt->operations_tracking : [];
            $issues = is_array($tracking['issues'] ?? null) ? $tracking['issues'] : [];
            $issue = is_array($issues[$slot] ?? null) ? $issues[$slot] : [];
            if (! $attempt || $issue === [] || ! empty($issue['resolved_at'])) {
                return;
            }
            if ($reasonPrefix !== null && ! str_starts_with((string) ($issue['reason_code'] ?? ''), $reasonPrefix)) {
                return;
            }

            $resolvedAt = CarbonImmutable::now('UTC')->toIso8601String();
            $issue['resolved_at'] = $resolvedAt;
            if (is_array($issue['alert'] ?? null)
                && in_array((string) ($issue['alert']['state'] ?? ''), ['pending', 'retryable'], true)) {
                $issue['alert']['state'] = 'suppressed';
                $issue['alert']['suppressed_at'] = $resolvedAt;
                $issue['alert']['next_attempt_at'] = null;
            }
            $issues[$slot] = $issue;
            $tracking['issues'] = $issues;
            $attempt->update([
                'operations_tracking' => $tracking,
                'operations_next_action_at' => $this->nextActionAt($tracking),
            ]);
            $this->auditLog->log('payment.operations.issue_resolved', null, $attempt, [
                'slot' => $slot,
                'episode_uuid' => $issue['episode_uuid'] ?? null,
                'reason_code' => $issue['reason_code'] ?? null,
                'resolved_at' => $resolvedAt,
            ], (int) $attempt->company_id);
        }, 3);
    }

    private function recoverQueuedAction(PaymentCheckoutAttempt $attempt): void
    {
        $tracking = is_array($attempt->operations_tracking) ? $attempt->operations_tracking : [];
        $recovery = is_array($tracking['recovery'] ?? null) ? $tracking['recovery'] : [];
        if (($recovery['state'] ?? null) === 'running' && ! empty($recovery['started_at'])
            && CarbonImmutable::parse((string) $recovery['started_at'])->addSeconds(120)->isPast()) {
            DB::transaction(function () use ($attempt): void {
                $locked = PaymentCheckoutAttempt::query()->lockForUpdate()->find($attempt->id);
                $tracking = is_array($locked?->operations_tracking) ? $locked->operations_tracking : [];
                $recovery = is_array($tracking['recovery'] ?? null) ? $tracking['recovery'] : [];
                if (($recovery['state'] ?? null) !== 'running' || empty($recovery['started_at'])
                    || ! CarbonImmutable::parse((string) $recovery['started_at'])->addSeconds(120)->isPast()) {
                    return;
                }
                $recovery['state'] = 'queued';
                $recovery['queued_at'] = now('UTC')->toIso8601String();
                $recovery['error_code'] = 'RECOVERY_CLAIM_STALE';
                $tracking['recovery'] = $recovery;
                $locked->update([
                    'operations_tracking' => $tracking,
                    'operations_next_action_at' => $this->nextActionAt($tracking),
                ]);
                $this->auditLog->log('payment.operations.recovery_requeued', null, $locked, [
                    'operation_uuid' => $recovery['operation_uuid'] ?? null,
                    'reason_code' => 'RECOVERY_CLAIM_STALE',
                ], (int) $locked->company_id);
            }, 3);
            $attempt->refresh();
            $tracking = is_array($attempt->operations_tracking) ? $attempt->operations_tracking : [];
            $recovery = is_array($tracking['recovery'] ?? null) ? $tracking['recovery'] : [];
        }

        if (($recovery['state'] ?? null) === 'queued' && ! empty($recovery['operation_uuid'])) {
            RetrySkipCashPaymentProcessing::dispatch((int) $attempt->id, (string) $recovery['operation_uuid']);
        }

        $resend = is_array($tracking['resend'] ?? null) ? $tracking['resend'] : [];
        if (($resend['state'] ?? null) === 'sending' && ! empty($resend['started_at'])
            && CarbonImmutable::parse((string) $resend['started_at'])->addSeconds(120)->isPast()) {
            DB::transaction(function () use ($attempt): void {
                $locked = PaymentCheckoutAttempt::query()->lockForUpdate()->find($attempt->id);
                $tracking = is_array($locked?->operations_tracking) ? $locked->operations_tracking : [];
                $resend = is_array($tracking['resend'] ?? null) ? $tracking['resend'] : [];
                if (($resend['state'] ?? null) !== 'sending' || empty($resend['started_at'])
                    || ! CarbonImmutable::parse((string) $resend['started_at'])->addSeconds(120)->isPast()) {
                    return;
                }
                $resend['state'] = 'unknown';
                $resend['error_code'] = 'EMAIL_DELIVERY_UNKNOWN';
                $resend['completed_at'] = now('UTC')->toIso8601String();
                $tracking['resend'] = $resend;
                $locked->update([
                    'operations_tracking' => $tracking,
                    'operations_next_action_at' => $this->nextActionAt($tracking),
                ]);
                $this->auditLog->log('payment.operations.resend_unknown', null, $locked, [
                    'operation_uuid' => $resend['operation_uuid'] ?? null,
                    'reason_code' => 'EMAIL_DELIVERY_UNKNOWN',
                ], (int) $locked->company_id);
            }, 3);

            return;
        }
        if (($resend['state'] ?? null) === 'queued' && ! empty($resend['operation_uuid'])) {
            ResendSkipCashOrderConfirmation::dispatch((int) $attempt->id, (string) $resend['operation_uuid']);
        }
    }

    private function boundedReason(string $reasonCode): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9_]/i', '', $reasonCode) ?: 'PAYMENT_PROCESSING_FAILED');

        return substr($normalized, 0, 80);
    }

    private function storeAlertSnapshot(PaymentCheckoutAttempt $attempt, string $episodeUuid, string $slot, string $reasonCode): void
    {
        $snapshots = is_array($attempt->notification_snapshots) ? $attempt->notification_snapshots : [];
        $alerts = is_array($snapshots['operations_alerts'] ?? null) ? $snapshots['operations_alerts'] : [];
        try {
            $recipients = $this->mailSettings->adminRecipientsForCompany((int) $attempt->company_id);
        } catch (MailConfigurationUnavailableException) {
            $recipients = [];
        }
        $alerts[$episodeUuid] = [
            'admin_emails' => $recipients,
            'reference' => (string) $attempt->reference,
            'slot' => $slot,
            'reason_code' => $reasonCode,
            'url' => url('/receivables/payments/skipcash/'.$attempt->id),
        ];
        $snapshots['operations_alerts'] = $alerts;
        $attempt->notification_snapshots = $snapshots;
        $attempt->save();
    }
}

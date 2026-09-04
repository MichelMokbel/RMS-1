<?php

namespace App\Services\Payments;

use App\Jobs\SendPaymentOperationsAlert;
use App\Models\PaymentCheckoutAttempt;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentOperationsTrackingService
{
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
        'CAPTURE_MISMATCH',
        'ADDITIONAL_CAPTURE_REVIEW',
    ];

    public function __construct(
        private readonly AccountingAuditLogService $auditLog,
        private readonly AccountingContextService $accountingContext,
    ) {}

    public function recordProcessingFailure(int $attemptId, string $reasonCode): void
    {
        $dispatch = false;
        $episodeUuid = null;

        DB::transaction(function () use ($attemptId, $reasonCode, &$dispatch, &$episodeUuid): void {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($attemptId);
            if (! $attempt || $attempt->state !== 'paid_processing') {
                return;
            }

            $now = CarbonImmutable::now('UTC');
            $tracking = is_array($attempt->operations_tracking) ? $attempt->operations_tracking : [];
            $issues = is_array($tracking['issues'] ?? null) ? $tracking['issues'] : [];
            $issue = is_array($issues['processing'] ?? null) ? $issues['processing'] : [];
            $isOpen = $issue !== [] && empty($issue['resolved_at']);
            $opened = ! $isOpen;
            $oldReason = $isOpen ? (string) ($issue['reason_code'] ?? '') : null;

            if ($opened) {
                $episodeUuid = (string) Str::uuid();
                $issue = [
                    'episode_uuid' => $episodeUuid,
                    'reason_code' => $this->boundedReason($reasonCode),
                    'first_seen_at' => $now->toIso8601String(),
                    'last_seen_at' => $now->toIso8601String(),
                    'attention_at' => $this->isImmediate($reasonCode)
                        ? $now->toIso8601String()
                        : $now->addMinutes(15)->toIso8601String(),
                    'resolved_at' => null,
                ];
            } else {
                $episodeUuid = (string) $issue['episode_uuid'];
                $issue['reason_code'] = $this->boundedReason($reasonCode);
                $issue['last_seen_at'] = $now->toIso8601String();
                if ($this->isImmediate($reasonCode)
                    && CarbonImmutable::parse((string) $issue['attention_at'])->isFuture()) {
                    $issue['attention_at'] = $now->toIso8601String();
                }
            }

            if ($now->greaterThanOrEqualTo(CarbonImmutable::parse((string) $issue['attention_at']))
                && ! is_array($issue['alert'] ?? null)) {
                $issue['alert'] = [
                    'episode_uuid' => $episodeUuid,
                    'kind' => 'admin_issue_alert',
                    'state' => 'pending',
                    'attempts' => 0,
                    'next_attempt_at' => $now->toIso8601String(),
                ];
                $this->storeAlertSnapshot($attempt, $episodeUuid, (string) $issue['reason_code']);
                $dispatch = true;
            }

            $issues['processing'] = $issue;
            $tracking['issues'] = $issues;
            $attempt->update([
                'operations_tracking' => $tracking,
                'operations_next_action_at' => $this->nextActionAt($tracking),
            ]);

            if ($opened) {
                $this->auditLog->log('payment.operations.issue_opened', null, $attempt, [
                    'slot' => 'processing',
                    'episode_uuid' => $episodeUuid,
                    'reason_code' => $issue['reason_code'],
                    'attention_at' => $issue['attention_at'],
                ], (int) $attempt->company_id);
            } elseif ($oldReason !== $issue['reason_code']) {
                $this->auditLog->log('payment.operations.issue_updated', null, $attempt, [
                    'slot' => 'processing',
                    'episode_uuid' => $episodeUuid,
                    'before_reason_code' => $oldReason,
                    'reason_code' => $issue['reason_code'],
                    'attention_at' => $issue['attention_at'],
                ], (int) $attempt->company_id);
            }
            if ($dispatch) {
                $this->auditLog->log('payment.operations.alert_intended', null, $attempt, [
                    'slot' => 'processing',
                    'episode_uuid' => $episodeUuid,
                    'reason_code' => $issue['reason_code'],
                ], (int) $attempt->company_id);
            }
        }, 3);

        if ($dispatch && $episodeUuid) {
            SendPaymentOperationsAlert::dispatch($attemptId, $episodeUuid);
        }
    }

    public function resolveProcessingIssue(int $attemptId): void
    {
        DB::transaction(function () use ($attemptId): void {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($attemptId);
            $tracking = is_array($attempt?->operations_tracking) ? $attempt->operations_tracking : [];
            $issues = is_array($tracking['issues'] ?? null) ? $tracking['issues'] : [];
            $issue = is_array($issues['processing'] ?? null) ? $issues['processing'] : [];
            if (! $attempt || $issue === [] || ! empty($issue['resolved_at'])) {
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
            $issues['processing'] = $issue;
            $tracking['issues'] = $issues;
            $attempt->update([
                'operations_tracking' => $tracking,
                'operations_next_action_at' => $this->nextActionAt($tracking),
            ]);
            $this->auditLog->log('payment.operations.issue_resolved', null, $attempt, [
                'slot' => 'processing',
                'episode_uuid' => $issue['episode_uuid'] ?? null,
                'reason_code' => $issue['reason_code'] ?? null,
                'resolved_at' => $resolvedAt,
            ], (int) $attempt->company_id);
        }, 3);
    }

    public function observeOutstanding(int $limit = 100): int
    {
        $ids = PaymentCheckoutAttempt::query()
            ->where('state', 'paid_processing')
            ->whereNotNull('last_error_code')
            ->orderBy('id')
            ->limit(max(1, min(100, $limit)))
            ->pluck('id');

        $newAlertIds = [];
        foreach ($ids as $id) {
            $attempt = PaymentCheckoutAttempt::query()->find((int) $id);
            if ($attempt) {
                $hadAlert = is_array(data_get($attempt->operations_tracking, 'issues.processing.alert'));
                $this->recordProcessingFailure($attempt->id, (string) $attempt->last_error_code);
                if (! $hadAlert && is_array(data_get($attempt->fresh()->operations_tracking, 'issues.processing.alert'))) {
                    $newAlertIds[] = (int) $id;
                }
            }
        }

        $dueIds = PaymentCheckoutAttempt::query()
            ->whereNotNull('operations_next_action_at')
            ->where('operations_next_action_at', '<=', now('UTC'))
            ->orderBy('id')
            ->limit(max(1, min(100, $limit)))
            ->pluck('id');
        foreach ($dueIds as $id) {
            if (in_array((int) $id, $newAlertIds, true)) {
                continue;
            }
            $attempt = PaymentCheckoutAttempt::query()->find((int) $id);
            $issue = $attempt?->operations_tracking['issues']['processing'] ?? null;
            $alert = is_array($issue) ? ($issue['alert'] ?? null) : null;
            if (is_array($issue) && empty($issue['resolved_at']) && is_array($alert)
                && in_array((string) ($alert['state'] ?? ''), ['pending', 'retryable'], true)) {
                SendPaymentOperationsAlert::dispatch((int) $id, (string) $issue['episode_uuid']);
            }
        }

        return $ids->count();
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
            } elseif (in_array((string) ($alert['state'] ?? ''), ['pending', 'retryable'], true)) {
                $dates[] = CarbonImmutable::parse((string) ($alert['next_attempt_at'] ?? now('UTC')));
            }
        }

        $recovery = is_array($tracking['recovery'] ?? null) ? $tracking['recovery'] : null;
        if ($recovery && ($recovery['state'] ?? null) === 'queued') {
            $dates[] = CarbonImmutable::parse((string) ($recovery['queued_at'] ?? now('UTC')));
        }

        return $dates === [] ? null : collect($dates)->sort()->first();
    }

    private function isImmediate(string $reasonCode): bool
    {
        return in_array($reasonCode, self::IMMEDIATE_PROCESSING_CODES, true);
    }

    private function boundedReason(string $reasonCode): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9_]/i', '', $reasonCode) ?: 'PAYMENT_PROCESSING_FAILED');

        return substr($normalized, 0, 80);
    }

    private function storeAlertSnapshot(PaymentCheckoutAttempt $attempt, string $episodeUuid, string $reasonCode): void
    {
        $snapshots = is_array($attempt->notification_snapshots) ? $attempt->notification_snapshots : [];
        $alerts = is_array($snapshots['operations_alerts'] ?? null) ? $snapshots['operations_alerts'] : [];
        $recipients = [];
        if ((int) $attempt->company_id === (int) $this->accountingContext->defaultCompanyId()) {
            $recipients = collect((array) config('mail.daily_dish_admin_emails', []))
                ->filter(fn ($email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
                ->unique()
                ->values()
                ->all();
        }
        $alerts[$episodeUuid] = [
            'admin_emails' => $recipients,
            'reference' => (string) $attempt->reference,
            'reason_code' => $reasonCode,
            'url' => url('/receivables/payments/skipcash/'.$attempt->id),
        ];
        $snapshots['operations_alerts'] = $alerts;
        $attempt->notification_snapshots = $snapshots;
        $attempt->save();
    }
}

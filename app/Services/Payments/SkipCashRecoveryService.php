<?php

namespace App\Services\Payments;

use App\Jobs\InitiateSkipCashCheckout;
use App\Jobs\SendSkipCashOrderConfirmation;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentProviderTransaction;
use Illuminate\Support\Facades\DB;

class SkipCashRecoveryService
{
    public function __construct(
        private readonly SkipCashWebhookService $webhooks,
        private readonly PaymentOperationsTrackingService $operations,
    ) {}

    /** @return array<string, int> */
    public function recover(int $limit = 100): array
    {
        $limit = max(1, min($limit, (int) config('payments.skipcash.recovery_batch_size', 100)));

        return [
            'expired' => $this->expireUnpaidAttempts($limit),
            'initiation_dispatched' => $this->dispatchUnsentAttempts($limit),
            'marked_unknown' => $this->markStaleDispatchesUnknown($limit),
            'details_checked' => $this->recoverKnownProviderSessions($limit),
            'events_retried' => $this->recoverRetryableEvents($limit),
            'confirmations_retried' => $this->recoverRetryableConfirmations($limit),
            'operations_observed' => $this->operations->observeOutstanding($limit),
        ];
    }

    public function purgeRawEventBodies(?int $retentionDays = null): int
    {
        $days = max(90, $retentionDays ?? (int) config('payments.skipcash.raw_event_retention_days', 90));
        $threshold = now('UTC')->subDays($days);
        $purged = 0;

        PaymentProviderEvent::query()
            ->whereNotNull('raw_body')
            ->whereNull('raw_body_removed_at')
            ->where('received_at', '<', $threshold)
            ->orderBy('id')
            ->chunkById(100, function ($events) use (&$purged): void {
                foreach ($events as $event) {
                    $changed = PaymentProviderEvent::query()
                        ->whereKey($event->id)
                        ->whereNotNull('raw_body')
                        ->whereNull('raw_body_removed_at')
                        ->update([
                            'raw_body' => null,
                            'raw_body_removed_at' => now('UTC'),
                        ]);
                    $purged += $changed;
                }
            });

        return $purged;
    }

    private function expireUnpaidAttempts(int $limit): int
    {
        $ids = PaymentCheckoutAttempt::query()
            ->whereIn('state', ['initiating', 'pending'])
            ->whereIn('provider_create_outcome', ['not_sent', 'created'])
            ->whereNull('financial_intent')
            ->where('expires_at', '<=', now('UTC'))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $expired = 0;

        foreach ($ids as $id) {
            if ($this->expireUnpaidAttempt((int) $id)) {
                $expired++;
            }
        }

        return $expired;
    }

    private function expireUnpaidAttempt(int $attemptId): bool
    {
        return DB::transaction(function () use ($attemptId): bool {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($attemptId);
            if (! $attempt || ! in_array($attempt->state, ['initiating', 'pending'], true)
                || ! in_array($attempt->provider_create_outcome, ['not_sent', 'created'], true)
                || $attempt->financial_intent !== null
                || ! $attempt->expires_at?->isPast()) {
                return false;
            }
            $nextRecovery = $attempt->provider_create_outcome === 'created'
                ? now('UTC')->addMinutes((int) config('payments.skipcash.detail_recheck_minutes', 5))
                : null;
            $attempt->update([
                'state' => 'expired',
                'last_error_code' => 'CHECKOUT_EXPIRED_UNPAID',
                'next_recovery_at' => $nextRecovery,
            ]);
            PaymentCheckoutTarget::query()
                ->where('attempt_id', $attempt->id)
                ->where('hold_state', 'held')
                ->lockForUpdate()
                ->update([
                    'hold_state' => 'released',
                    'released_at' => now('UTC'),
                ]);

            return true;
        }, 3);
    }

    private function dispatchUnsentAttempts(int $limit): int
    {
        $ids = PaymentCheckoutAttempt::query()
            ->where('provider_create_outcome', 'not_sent')
            ->whereIn('state', ['initiating', 'pending'])
            ->where('expires_at', '>', now('UTC'))
            ->where(function ($query): void {
                $query->whereNull('next_recovery_at')->orWhere('next_recovery_at', '<=', now('UTC'));
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $dispatched = 0;

        foreach ($ids as $id) {
            if (! $this->claimUnsentAttemptForDispatch((int) $id)) {
                continue;
            }
            InitiateSkipCashCheckout::dispatch((int) $id);
            $dispatched++;
        }

        return $dispatched;
    }

    private function claimUnsentAttemptForDispatch(int $attemptId): bool
    {
        return DB::transaction(function () use ($attemptId): bool {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($attemptId);
            if (! $attempt || $attempt->provider_create_outcome !== 'not_sent'
                || ! in_array($attempt->state, ['initiating', 'pending'], true)
                || $attempt->expires_at?->isPast()) {
                return false;
            }
            $attempt->update([
                'next_recovery_at' => now('UTC')->addMinute(),
                'last_error_code' => null,
            ]);

            return true;
        }, 3);
    }

    private function markStaleDispatchesUnknown(int $limit): int
    {
        $cutoff = now('UTC')->subSeconds(max(1, (int) config('payments.skipcash.stale_claim_seconds', 60)));
        $ids = PaymentCheckoutAttempt::query()
            ->where('provider_create_outcome', 'in_flight')
            ->where('provider_dispatched_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $changed = 0;

        foreach ($ids as $id) {
            $updated = PaymentCheckoutAttempt::query()
                ->whereKey($id)
                ->where('provider_create_outcome', 'in_flight')
                ->where('provider_dispatched_at', '<=', $cutoff)
                ->update([
                    'provider_create_outcome' => 'unknown',
                    'last_error_code' => 'PROVIDER_CREATE_UNKNOWN',
                    'next_recovery_at' => null,
                ]);
            $changed += $updated;
            if ($updated) {
                $this->operations->recordProviderEvidenceIssue((int) $id, 'PROVIDER_CREATE_UNKNOWN');
            }
        }

        return $changed;
    }

    private function recoverKnownProviderSessions(int $limit): int
    {
        $ids = PaymentCheckoutAttempt::query()
            ->where('provider_create_outcome', 'created')
            ->whereIn('state', ['pending', 'expired'])
            ->whereNotNull('next_recovery_at')
            ->where('next_recovery_at', '<=', now('UTC'))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $checked = 0;

        foreach ($ids as $attemptId) {
            $transactionId = $this->claimKnownSessionForRecovery((int) $attemptId);
            if (! $transactionId) {
                continue;
            }
            $this->webhooks->recoverKnownTransaction($transactionId);
            $checked++;
        }

        return $checked;
    }

    private function claimKnownSessionForRecovery(int $attemptId): ?int
    {
        return DB::transaction(function () use ($attemptId): ?int {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($attemptId);
            if (! $attempt || $attempt->provider_create_outcome !== 'created'
                || ! in_array($attempt->state, ['pending', 'expired'], true)
                || ! $attempt->next_recovery_at?->isPast()) {
                return null;
            }
            $transaction = PaymentProviderTransaction::query()
                ->where('attempt_id', $attempt->id)
                ->where('normalized_status', 'pending')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if (! $transaction) {
                return null;
            }
            $attempt->update([
                'next_recovery_at' => now('UTC')->addMinutes((int) config('payments.skipcash.detail_recheck_minutes', 5)),
            ]);

            return $transaction->id;
        }, 3);
    }

    private function recoverRetryableEvents(int $limit): int
    {
        $cutoff = now('UTC')->subSeconds(max(1, (int) config('payments.skipcash.stale_claim_seconds', 60)));
        PaymentProviderEvent::query()
            ->where('processing_state', 'processing')
            ->where('processing_started_at', '<=', $cutoff)
            ->update([
                'processing_state' => 'retryable',
                'next_retry_at' => now('UTC'),
                'error_code' => 'PROCESSING_CLAIM_STALE',
            ]);

        $ids = PaymentProviderEvent::query()
            ->where('processing_state', 'retryable')
            ->where('normalized_status', 'paid')
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now('UTC'))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $retried = 0;
        foreach ($ids as $eventId) {
            $this->webhooks->recoverEvent((int) $eventId);
            $retried++;
        }

        return $retried;
    }

    private function recoverRetryableConfirmations(int $limit): int
    {
        $dispatched = 0;
        foreach (SendSkipCashOrderConfirmation::AUDIENCES as $audience) {
            $slot = SendSkipCashOrderConfirmation::slotForAudience($audience);
            if ($slot === null || $dispatched >= $limit) {
                continue;
            }
            $nextRetryPath = '$.'.$slot.'.next_retry_at';
            $statePath = '$.'.$slot.'.state';
            $ids = PaymentCheckoutAttempt::query()
                ->where('state', 'completed')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(notification_dispatch, '{$statePath}')) = ?", ['retryable'])
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(notification_dispatch, '{$nextRetryPath}')) <= ?", [now('UTC')->toIso8601String()])
                ->orderBy('id')
                ->limit($limit - $dispatched)
                ->pluck('id');
            foreach ($ids as $attemptId) {
                SendSkipCashOrderConfirmation::dispatch((int) $attemptId, $audience);
                $dispatched++;
            }
        }

        return $dispatched;
    }
}

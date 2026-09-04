<?php

namespace App\Services\Payments;

use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentProviderEvent;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PaymentOperationsEvidenceService
{
    public function __construct(
        private readonly PaymentOperationsAccessService $access,
        private readonly AccountingAuditLogService $auditLog,
    ) {}

    /** @return array<string, mixed> */
    public function inspect(int $attemptId, int $eventId, User $actor): array
    {
        if (! Schema::hasTable('accounting_audit_logs')) {
            throw ValidationException::withMessages(['evidence' => __('Payment evidence is unavailable because audit storage is unavailable.')]);
        }

        return DB::transaction(function () use ($attemptId, $eventId, $actor): array {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            $this->access->assertCanView($actor, $attempt);
            $rateKey = 'payment-operations-evidence:'.$actor->id.':'.$attempt->id;
            if (RateLimiter::tooManyAttempts($rateKey, 60)) {
                throw ValidationException::withMessages(['evidence' => __('Too many evidence requests. Please wait a minute.')]);
            }
            RateLimiter::hit($rateKey, 60);

            $providerTransactionIds = $attempt->providerTransactions()->pluck('id');
            $merchantReference = str_replace('-', '', (string) $attempt->reference);
            $event = PaymentProviderEvent::query()
                ->whereKey($eventId)
                ->where('payment_source_id', $attempt->payment_source_id)
                ->where(function ($query) use ($providerTransactionIds, $merchantReference): void {
                    $query->where('merchant_transaction_id', $merchantReference);
                    if ($providerTransactionIds->isNotEmpty()) {
                        $query->orWhereIn('provider_transaction_id', $providerTransactionIds);
                    }
                })
                ->lockForUpdate()
                ->firstOrFail();

            $normalized = is_array($event->normalized_snapshot) ? $event->normalized_snapshot : [];
            $result = [
                'event_id' => (int) $event->id,
                'received_at' => $event->received_at?->toIso8601String(),
                'normalized_status' => (string) $event->normalized_status,
                'processing_state' => (string) $event->processing_state,
                'error_code' => $event->error_code,
                'raw_evidence' => $event->raw_body_removed_at ? 'purged' : ($event->raw_body === null ? 'not_retained' : 'retained_private'),
                'normalized' => Arr::only($normalized, [
                    'evidence_source',
                    'payment_id',
                    'merchant_transaction_id',
                    'amount_cents',
                    'currency',
                    'status_id',
                    'finished_at',
                ]),
            ];
            $this->auditLog->log('payment.operations.evidence_inspected', (int) $actor->id, $attempt, [
                'event_id' => (int) $event->id,
                'raw_evidence' => $result['raw_evidence'],
            ], (int) $attempt->company_id);

            return $result;
        }, 3);
    }
}

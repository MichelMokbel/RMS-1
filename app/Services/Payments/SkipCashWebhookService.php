<?php

namespace App\Services\Payments;

use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentProviderTransaction;
use App\Services\Promotions\MembershipPromotionReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SkipCashWebhookService
{
    public function __construct(
        private readonly SkipCashProvider $provider,
        private readonly OrdinaryOrderActivationService $activation,
        private readonly MembershipCheckoutActivationService $membershipActivation,
        private readonly PaymentOperationsTrackingService $operations,
        private readonly MembershipPromotionReservationService $promotionReservations,
        private readonly PaymentConsistencyDispatchService $paymentConsistency,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{duplicate:bool,completed:bool}
     */
    public function handle(array $payload, ?string $authorization, string $rawBody): array
    {
        $signed = $this->verifiedFields($payload, $authorization);
        $attempt = $this->resolveAttempt($signed['transaction_id']);
        $event = $this->retainEvent($attempt, $signed, $rawBody);
        if ($event['duplicate']) {
            return ['duplicate' => true, 'completed' => $event['event']->processing_state === 'processed'];
        }

        $providerEvent = $event['event'];
        if ($signed['status_id'] !== '2') {
            $this->recordNonPaidEvent($attempt->id, $providerEvent->id, $signed['status_id']);

            return ['duplicate' => false, 'completed' => false];
        }

        try {
            return [
                'duplicate' => false,
                'completed' => $this->processRetainedPaidEvent($providerEvent->id),
            ];
        } catch (PaymentCheckoutException $exception) {
            throw new PaymentCheckoutException('PAYMENT_PROCESSING_FAILED', 503, __('Payment confirmation is being retried.'));
        } catch (\Throwable) {
            throw new PaymentCheckoutException(
                'PAYMENT_PROCESSING_FAILED',
                503,
                __('Payment confirmation is being retried.'),
            );
        }
    }

    /**
     * Reprocess provider evidence that was already authenticated and retained.
     * This is intentionally idempotent and is only called by the scheduled
     * recovery service, never from a browser request.
     */
    public function recoverEvent(int $eventId): bool
    {
        try {
            return $this->processRetainedPaidEvent($eventId);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Query a known hosted session through the authenticated provider boundary.
     * It is the backstop when SkipCash cannot deliver a callback.
     */
    public function recoverKnownTransaction(int $providerTransactionId): bool
    {
        $context = DB::transaction(function () use ($providerTransactionId): ?array {
            $transaction = PaymentProviderTransaction::query()->lockForUpdate()->find($providerTransactionId);
            if (! $transaction || $transaction->normalized_status !== 'pending') {
                return null;
            }
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($transaction->attempt_id);
            if (! $attempt || ! in_array($attempt->state, ['pending', 'expired'], true)
                || $attempt->provider_create_outcome !== 'created') {
                return null;
            }

            return [
                'transaction_id' => $transaction->id,
                'attempt_id' => $attempt->id,
                'source_id' => $attempt->payment_source_id,
                'provider_payment_id' => $transaction->provider_payment_id,
                'merchant_transaction_id' => $transaction->merchant_transaction_id,
                'expected_amount_cents' => (int) $attempt->payable_amount_cents,
            ];
        }, 3);
        if (! $context) {
            return false;
        }

        try {
            $details = $this->provider->details((string) $context['provider_payment_id']);
            $this->assertDetailsIdentity($context, $details);
            $status = (string) ($details['status_id'] ?? '');
            if ($status === '2') {
                $event = $this->retainDetailEvent($context, $details);

                return $this->processRetainedPaidEvent($event->id, $details);
            }

            $this->recordKnownUnpaidDetails($context, $details);

            return false;
        } catch (PaymentCheckoutException $exception) {
            $this->recordKnownTransactionError((int) $context['attempt_id'], $exception->codeName);
        } catch (\Throwable) {
            $this->recordKnownTransactionError((int) $context['attempt_id'], 'PROVIDER_DETAILS_UNAVAILABLE');
        }

        return false;
    }

    private function processRetainedPaidEvent(int $eventId, ?array $details = null): bool
    {
        $context = DB::transaction(function () use ($eventId): ?array {
            $event = PaymentProviderEvent::query()->lockForUpdate()->find($eventId);
            if (! $event || $event->normalized_status !== 'paid'
                || in_array($event->processing_state, ['processed', 'quarantined'], true)) {
                return null;
            }
            $attempt = $this->resolveAttempt((string) $event->merchant_transaction_id);
            if ((int) $event->payment_source_id !== (int) $attempt->payment_source_id) {
                throw new PaymentCheckoutException('CAPTURE_MISMATCH', 409, __('The payment evidence does not match this checkout.'));
            }
            $event->update([
                'processing_state' => 'processing',
                'processing_started_at' => now('UTC'),
            ]);
            $snapshot = is_array($event->normalized_snapshot) ? $event->normalized_snapshot : [];

            return [
                'event_id' => $event->id,
                'attempt_id' => $attempt->id,
                'payment_id' => $event->provider_payment_id,
                'signed' => [
                    'payment_id' => $event->provider_payment_id,
                    'amount_cents' => (int) $event->amount_cents,
                    'status_id' => (string) $event->raw_status,
                    'transaction_id' => (string) $event->merchant_transaction_id,
                    'visa_id' => trim((string) ($snapshot['visa_id'] ?? '')),
                ],
            ];
        }, 3);
        if (! $context) {
            return false;
        }

        try {
            $details ??= $this->provider->details((string) $context['payment_id']);
            $providerTransaction = $this->acceptCapture(
                (int) $context['attempt_id'],
                (int) $context['event_id'],
                $context['signed'],
                $details,
            );
            $this->operations->resolveProviderEvidenceIssue((int) $context['attempt_id']);
            $attempt = PaymentCheckoutAttempt::query()->findOrFail((int) $context['attempt_id']);
            match ($attempt->purpose) {
                'ordinary_order' => $this->activation->complete($attempt->id, $providerTransaction->id),
                'membership' => $this->membershipActivation->complete($attempt->id, $providerTransaction->id),
                default => throw new PaymentCheckoutException(
                    'CHECKOUT_PURPOSE_INVALID',
                    409,
                    __('This payment purpose is not supported.'),
                ),
            };
            $this->markEventProcessed((int) $context['event_id']);
            $this->operations->resolveProcessingIssue((int) $context['attempt_id']);
            $this->paymentConsistency->checkoutGraphAfterCommit(
                (int) $context['attempt_id'],
                'payment_provider_event',
                (int) $context['event_id'],
                'processed',
            );

            return true;
        } catch (PaymentCheckoutException $exception) {
            $this->recordEventFailure((int) $context['event_id'], (int) $context['attempt_id'], $exception->codeName);

            throw $exception;
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->recordEventFailure((int) $context['event_id'], (int) $context['attempt_id'], 'FINANCIAL_PERIOD_BLOCKED');

            throw new PaymentCheckoutException('FINANCIAL_PERIOD_BLOCKED', 503, __('Payment accounting is blocked and needs attention.'));
        } catch (\Throwable) {
            $this->recordEventFailure((int) $context['event_id'], (int) $context['attempt_id'], 'PAYMENT_PROCESSING_FAILED');

            throw new PaymentCheckoutException(
                'PAYMENT_PROCESSING_FAILED',
                503,
                __('Payment confirmation is being retried.'),
            );
        }
    }

    private function markEventProcessed(int $eventId): void
    {
        DB::transaction(function () use ($eventId): void {
            $event = PaymentProviderEvent::query()->lockForUpdate()->find($eventId);
            if (! $event) {
                return;
            }
            $event->update([
                'processing_state' => 'processed',
                'processed_at' => now('UTC'),
                'next_retry_at' => null,
                'error_code' => null,
            ]);
        }, 3);
    }

    private function recordEventFailure(int $eventId, int $attemptId, string $code): void
    {
        DB::transaction(function () use ($eventId, $attemptId, $code): void {
            $event = PaymentProviderEvent::query()->lockForUpdate()->find($eventId);
            if (! $event || in_array($event->processing_state, ['processed', 'quarantined'], true)) {
                return;
            }
            $snapshot = is_array($event->normalized_snapshot) ? $event->normalized_snapshot : [];
            $attempts = max(0, (int) ($snapshot['recovery_attempts'] ?? 0)) + 1;
            $snapshot['recovery_attempts'] = $attempts;
            $retryable = $this->isRetryableFailure($code)
                && $attempts < (int) config('payments.skipcash.recovery_max_attempts', 5);
            $nextRetryAt = $retryable ? now('UTC')->addMinutes($this->retryDelayMinutes($attempts)) : null;
            $event->update([
                'normalized_snapshot' => $snapshot,
                'processing_state' => $retryable ? 'retryable' : 'quarantined',
                'error_code' => $code,
                'next_retry_at' => $nextRetryAt,
            ]);

            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($attemptId);
            if ($attempt && $attempt->state !== 'completed') {
                $attempt->update([
                    'last_error_code' => $code,
                    'next_recovery_at' => $nextRetryAt,
                ]);
            }
        }, 3);
        if ($this->isProviderEvidenceFailure($code)) {
            $this->operations->recordProviderEvidenceIssue($attemptId, $code);
        } else {
            $this->operations->recordProcessingFailure($attemptId, $code);
        }
        $this->paymentConsistency->checkoutGraphAfterCommit(
            $attemptId,
            'payment_provider_event',
            $eventId,
            'processing_failed',
        );
    }

    private function recordNonPaidEvent(int $attemptId, int $eventId, string $status): void
    {
        $requiresReview = DB::transaction(function () use ($attemptId, $eventId, $status): bool {
            $event = PaymentProviderEvent::query()->lockForUpdate()->findOrFail($eventId);
            $event->update([
                'processing_state' => 'processed',
                'processed_at' => now('UTC'),
                'next_retry_at' => null,
                'error_code' => null,
            ]);
            if (! in_array($status, ['3', '4', '5'], true)) {
                return false;
            }

            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if ($attempt->state === 'completed' || is_array($attempt->financial_intent) && $attempt->financial_intent !== []) {
                return true;
            }
            $attempt->update([
                'state' => 'declined',
                'last_error_code' => 'PROVIDER_UNPAID_TERMINAL',
                'next_recovery_at' => null,
            ]);
            PaymentCheckoutTarget::query()
                ->where('attempt_id', $attempt->id)
                ->where('hold_state', 'held')
                ->lockForUpdate()
                ->update([
                    'hold_state' => 'released',
                    'released_at' => now('UTC'),
                ]);
            $this->promotionReservations->releaseForCheckout(
                (int) $attempt->id,
                'provider_unpaid_terminal',
            );

            return false;
        }, 3);
        if ($requiresReview) {
            $this->operations->recordProviderEvidenceIssue($attemptId, 'PROVIDER_REVERSAL_REVIEW');
        }
        $this->paymentConsistency->checkoutGraphAfterCommit(
            $attemptId,
            'payment_provider_event',
            $eventId,
            'unpaid_processed',
        );
    }

    /** @param array<string, mixed> $context
     * @param  array<string, mixed>  $details
     */
    private function assertDetailsIdentity(array $context, array $details): void
    {
        if ((string) ($details['provider_payment_id'] ?? '') !== (string) $context['provider_payment_id']
            || (string) ($details['merchant_transaction_id'] ?? '') !== (string) $context['merchant_transaction_id']
            || $this->amountToCents($details['amount'] ?? null) !== (int) $context['expected_amount_cents']
            || strtoupper((string) ($details['currency'] ?? '')) !== 'QAR') {
            throw new PaymentCheckoutException('CAPTURE_MISMATCH', 409, __('The payment evidence does not match this checkout.'));
        }
    }

    /** @param array<string, mixed> $context
     * @param  array<string, mixed>  $details
     */
    private function retainDetailEvent(array $context, array $details): PaymentProviderEvent
    {
        $amountCents = $this->amountToCents($details['amount'] ?? null);
        $status = (string) ($details['status_id'] ?? '');
        $snapshot = [
            'evidence_source' => 'scheduled_details',
            'payment_id' => (string) $context['provider_payment_id'],
            'merchant_transaction_id' => (string) $context['merchant_transaction_id'],
            'amount_cents' => $amountCents,
            'currency' => 'QAR',
            'status_id' => $status,
            'finished_at' => (string) ($details['finished_at'] ?? ''),
            'visa_id' => trim((string) ($details['visa_id'] ?? '')),
            'card_type' => trim((string) ($details['card_type'] ?? '')),
        ];
        $payloadHash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($context, $snapshot, $payloadHash, $amountCents, $status): PaymentProviderEvent {
            $event = PaymentProviderEvent::query()
                ->where('payment_source_id', $context['source_id'])
                ->where('payload_hash', $payloadHash)
                ->lockForUpdate()
                ->first();
            if ($event) {
                return $event;
            }

            return PaymentProviderEvent::query()->create([
                'payment_source_id' => $context['source_id'],
                'provider_payment_id' => $context['provider_payment_id'],
                'payload_hash' => $payloadHash,
                'merchant_transaction_id' => $context['merchant_transaction_id'],
                'amount_cents' => $amountCents,
                'raw_status' => $status,
                'normalized_status' => $this->normalizedStatus($status),
                'normalized_snapshot' => $snapshot,
                'signature_key_reference' => 'details-authenticated',
                'processing_state' => 'pending',
                'received_at' => now('UTC'),
            ]);
        }, 3);
    }

    /** @param array<string, mixed> $context
     * @param  array<string, mixed>  $details
     */
    private function recordKnownUnpaidDetails(array $context, array $details): void
    {
        $status = (string) ($details['status_id'] ?? '');
        $normalized = $this->normalizedStatus($status);
        $requiresReview = DB::transaction(function () use ($context, $status, $normalized): bool {
            $transaction = PaymentProviderTransaction::query()->lockForUpdate()
                ->where('attempt_id', $context['attempt_id'])
                ->where('provider_payment_id', $context['provider_payment_id'])
                ->firstOrFail();
            $transaction->update([
                'raw_status' => $status,
                'normalized_status' => $normalized,
                'details_checked_at' => now('UTC'),
            ]);

            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->findOrFail($context['attempt_id']);
            if ($attempt->state !== 'completed') {
                $attempt->update([
                    'provider_detail_recovery_attempts' => 0,
                    'last_error_code' => null,
                ]);
            }

            if (in_array($status, ['3', '4', '5'], true)) {
                if ($attempt->state === 'completed' || is_array($attempt->financial_intent) && $attempt->financial_intent !== []) {
                    return true;
                }
                $this->declineAttemptIfUnpaid((int) $context['attempt_id']);
            }

            return false;
        }, 3);
        if ($requiresReview) {
            $this->operations->recordProviderEvidenceIssue((int) $context['attempt_id'], 'PROVIDER_REVERSAL_REVIEW');
        } else {
            $this->operations->resolveProviderEvidenceIssue((int) $context['attempt_id']);
        }
        $this->paymentConsistency->checkoutGraphAfterCommit(
            (int) $context['attempt_id'],
            'payment_provider_transaction',
            (int) $context['transaction_id'],
            'details_checked',
        );
    }

    private function recordKnownTransactionError(int $attemptId, string $code): void
    {
        $exhausted = DB::transaction(function () use ($attemptId, $code): bool {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($attemptId);
            if (! $attempt || $attempt->state === 'completed') {
                return false;
            }
            $attempts = (int) $attempt->provider_detail_recovery_attempts + 1;
            $retryable = $attempts < (int) config('payments.skipcash.recovery_max_attempts', 5);
            $attempt->update([
                'last_error_code' => $code,
                'provider_detail_recovery_attempts' => $attempts,
                'next_recovery_at' => $retryable
                    ? now('UTC')->addMinutes((int) config('payments.skipcash.detail_recheck_minutes', 5))
                    : null,
            ]);

            return ! $retryable;
        }, 3);
        if ($exhausted) {
            $this->operations->recordProviderEvidenceIssue($attemptId, $code);
        }
    }

    private function declineAttemptIfUnpaid(int $attemptId): void
    {
        $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->findOrFail($attemptId);
        if ($attempt->state === 'completed' || is_array($attempt->financial_intent) && $attempt->financial_intent !== []) {
            return;
        }
        $attempt->update([
            'state' => 'declined',
            'last_error_code' => 'PROVIDER_UNPAID_TERMINAL',
            'next_recovery_at' => null,
        ]);
        PaymentCheckoutTarget::query()
            ->where('attempt_id', $attempt->id)
            ->where('hold_state', 'held')
            ->lockForUpdate()
            ->update([
                'hold_state' => 'released',
                'released_at' => now('UTC'),
            ]);
        $this->promotionReservations->releaseForCheckout(
            (int) $attempt->id,
            'provider_unpaid_terminal',
        );
    }

    private function isRetryableFailure(string $code): bool
    {
        return ! in_array($code, [
            'CAPTURE_MISMATCH',
            'FINISH_TIME_MISSING',
            'FINISH_TIME_INVALID',
            'FINISH_TIME_FUTURE',
            'WEBHOOK_AMOUNT_INVALID',
            'WEBHOOK_REFERENCE_INVALID',
            'WEBHOOK_REFERENCE_UNKNOWN',
        ], true);
    }

    private function isProviderEvidenceFailure(string $code): bool
    {
        return in_array($code, [
            'CAPTURE_MISMATCH',
            'ADDITIONAL_CAPTURE_REVIEW',
            'FINISH_TIME_MISSING',
            'FINISH_TIME_INVALID',
            'FINISH_TIME_FUTURE',
            'WEBHOOK_AMOUNT_INVALID',
            'WEBHOOK_REFERENCE_INVALID',
            'WEBHOOK_REFERENCE_UNKNOWN',
        ], true);
    }

    private function retryDelayMinutes(int $attempts): int
    {
        $base = max(1, (int) config('payments.skipcash.recovery_retry_base_minutes', 1));

        return min(30, $base * (2 ** max(0, $attempts - 1)));
    }

    /**
     * @param  array<string, string>  $signed
     * @param  array<string, mixed>  $details
     */
    private function acceptCapture(int $attemptId, int $eventId, array $signed, array $details): PaymentProviderTransaction
    {
        return DB::transaction(function () use ($attemptId, $eventId, $signed, $details): PaymentProviderTransaction {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            $transaction = PaymentProviderTransaction::query()
                ->where('payment_source_id', $attempt->payment_source_id)
                ->where('provider_payment_id', $signed['payment_id'])
                ->lockForUpdate()
                ->first();
            if (! $transaction) {
                $transaction = PaymentProviderTransaction::query()->create([
                    'attempt_id' => $attempt->id,
                    'payment_source_id' => $attempt->payment_source_id,
                    'provider_payment_id' => $signed['payment_id'],
                    'merchant_transaction_id' => $signed['transaction_id'],
                    'amount_cents' => $signed['amount_cents'],
                    'currency' => 'QAR',
                    'raw_status' => $signed['status_id'],
                    'normalized_status' => 'pending',
                    'classification' => 'pending',
                ]);
            } elseif ((int) $transaction->attempt_id !== (int) $attempt->id
                || (string) $transaction->merchant_transaction_id !== (string) $signed['transaction_id']) {
                throw new PaymentCheckoutException('CAPTURE_MISMATCH', 409, __('The payment evidence does not match this checkout.'));
            }

            $this->assertDetailsMatch($attempt, $signed, $details);
            $finishedAt = $this->finishedAt($details['finished_at'] ?? null);
            $receiptDate = $finishedAt->setTimezone('Asia/Qatar')->toDateString();
            $detailsVisaId = trim((string) ($details['visa_id'] ?? ''));
            $signedVisaId = trim((string) ($signed['visa_id'] ?? ''));
            $visaId = $detailsVisaId !== '' ? $detailsVisaId : $signedVisaId;
            $cardType = trim((string) ($details['card_type'] ?? ''));
            $event = PaymentProviderEvent::query()->lockForUpdate()->findOrFail($eventId);
            $event->update([
                'provider_transaction_id' => $transaction->id,
                'normalized_status' => 'paid',
                'processing_state' => 'processing',
                'processing_started_at' => now('UTC'),
            ]);

            if ($transaction->verified_paid_at !== null) {
                return $transaction->fresh();
            }

            $intent = is_array($attempt->financial_intent) ? $attempt->financial_intent : [];
            if ($intent !== [] && (int) ($intent['provider_transaction_id'] ?? 0) !== $transaction->id) {
                throw new PaymentCheckoutException('ADDITIONAL_CAPTURE_REVIEW', 409, __('An additional payment requires review.'));
            }

            $transaction->update([
                'raw_status' => $signed['status_id'],
                'normalized_status' => 'paid',
                'finished_at' => $finishedAt,
                'finished_at_evidence_source' => 'provider_details',
                'verified_paid_at' => now('UTC'),
                'verified_amount_cents' => (int) $attempt->payable_amount_cents,
                'verified_currency' => 'QAR',
                'verified_finished_at' => $finishedAt,
                'details_checked_at' => now('UTC'),
                'receipt_date' => $receiptDate,
                'receipt_client_uuid' => (string) Str::uuid(),
                'visa_id' => $visaId !== '' ? $visaId : null,
                'card_type' => $cardType !== '' ? $cardType : null,
            ]);
            $attempt->update([
                'state' => 'paid_processing',
                'financial_intent' => [
                    'provider_transaction_id' => $transaction->id,
                    'allocation_date' => $receiptDate,
                    'invoice_issue_date' => $receiptDate,
                ],
                'next_recovery_at' => now('UTC'),
                'last_error_code' => null,
            ]);

            return $transaction->fresh();
        }, 3);
    }

    /** @param array<string, string> $signed
     * @param  array<string, mixed>  $details
     */
    private function assertDetailsMatch(PaymentCheckoutAttempt $attempt, array $signed, array $details): void
    {
        $amount = $this->amountToCents($details['amount'] ?? null);
        $signedVisaId = trim((string) ($signed['visa_id'] ?? ''));
        $detailsVisaId = trim((string) ($details['visa_id'] ?? ''));
        if ((string) ($details['provider_payment_id'] ?? '') !== $signed['payment_id']
            || (string) ($details['merchant_transaction_id'] ?? '') !== $signed['transaction_id']
            || (string) ($details['status_id'] ?? '') !== '2'
            || $amount !== (int) $attempt->payable_amount_cents
            || $signed['amount_cents'] !== (int) $attempt->payable_amount_cents
            || strtoupper((string) ($details['currency'] ?? '')) !== 'QAR'
            || ($signedVisaId !== '' && $detailsVisaId !== '' && ! hash_equals($signedVisaId, $detailsVisaId))) {
            throw new PaymentCheckoutException('CAPTURE_MISMATCH', 409, __('The payment evidence does not match this checkout.'));
        }
    }

    /** @return array{duplicate:bool,event:PaymentProviderEvent} */
    private function retainEvent(PaymentCheckoutAttempt $attempt, array $signed, string $rawBody): array
    {
        return DB::transaction(function () use ($attempt, $signed, $rawBody): array {
            $hash = hash('sha256', $rawBody);
            $existing = PaymentProviderEvent::query()
                ->where('payment_source_id', $attempt->payment_source_id)
                ->where('payload_hash', $hash)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return ['duplicate' => true, 'event' => $existing];
            }

            return [
                'duplicate' => false,
                'event' => PaymentProviderEvent::query()->create([
                    'payment_source_id' => $attempt->payment_source_id,
                    'provider_payment_id' => $signed['payment_id'],
                    'payload_hash' => $hash,
                    'merchant_transaction_id' => $signed['transaction_id'],
                    'amount_cents' => $signed['amount_cents'],
                    'raw_status' => $signed['status_id'],
                    'normalized_status' => $this->normalizedStatus($signed['status_id']),
                    'normalized_snapshot' => [
                        'payment_id' => $signed['payment_id'],
                        'amount_cents' => $signed['amount_cents'],
                        'status_id' => $signed['status_id'],
                        'merchant_transaction_id' => $signed['transaction_id'],
                        'visa_id' => trim((string) ($signed['visa_id'] ?? '')),
                    ],
                    'signature_key_reference' => 'webhook-current',
                    'processing_state' => 'pending',
                    'received_at' => now('UTC'),
                    'raw_body' => $rawBody,
                ]),
            ];
        }, 3);
    }

    /** @return array{payment_id:string,amount_cents:int,status_id:string,transaction_id:string,visa_id:string} */
    private function verifiedFields(array $payload, ?string $authorization): array
    {
        $fields = ['PaymentId', 'Amount', 'StatusId', 'TransactionId', 'Custom1', 'VisaId'];
        $parts = [];
        foreach ($fields as $field) {
            $value = $payload[$field] ?? null;
            if (! is_scalar($value) && $value !== null) {
                throw new PaymentCheckoutException('WEBHOOK_MALFORMED', 400, __('The payment callback is malformed.'));
            }
            if ($value !== null && (string) $value !== '') {
                $parts[] = $field.'='.(string) $value;
            }
        }
        $expected = base64_encode(hash_hmac(
            'sha256',
            implode(',', $parts),
            (string) config('payments.skipcash.webhook_secret'),
            true,
        ));
        if (! is_string($authorization) || ! hash_equals($expected, trim($authorization))) {
            throw new PaymentCheckoutException('WEBHOOK_SIGNATURE_INVALID', 401, __('The payment callback is not authorized.'));
        }
        if (trim((string) ($payload['Custom1'] ?? '')) !== '') {
            throw new PaymentCheckoutException('WEBHOOK_CUSTOM_FIELD_CONFLICT', 409, __('The payment callback cannot be processed.'));
        }

        $paymentId = trim((string) ($payload['PaymentId'] ?? ''));
        $transactionId = trim((string) ($payload['TransactionId'] ?? ''));
        $statusId = trim((string) ($payload['StatusId'] ?? ''));
        if ($paymentId === '' || $transactionId === '' || $statusId === '') {
            throw new PaymentCheckoutException('WEBHOOK_MALFORMED', 400, __('The payment callback is malformed.'));
        }

        return [
            'payment_id' => $paymentId,
            'amount_cents' => $this->amountToCents($payload['Amount'] ?? null),
            'status_id' => $statusId,
            'transaction_id' => $transactionId,
            'visa_id' => trim((string) ($payload['VisaId'] ?? '')),
        ];
    }

    private function resolveAttempt(string $merchantReference): PaymentCheckoutAttempt
    {
        $reference = strtolower($merchantReference);
        if (! preg_match('/^[a-f0-9]{32}$/', $reference)) {
            throw new PaymentCheckoutException('WEBHOOK_REFERENCE_INVALID', 409, __('The payment callback cannot be matched.'));
        }
        $attempt = PaymentCheckoutAttempt::query()
            ->whereRaw("REPLACE(reference, '-', '') = ?", [$reference])
            ->first();
        if (! $attempt) {
            throw new PaymentCheckoutException('WEBHOOK_REFERENCE_UNKNOWN', 409, __('The payment callback cannot be matched.'));
        }

        return $attempt;
    }

    private function amountToCents(mixed $value): int
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new PaymentCheckoutException('WEBHOOK_AMOUNT_INVALID', 400, __('The payment callback amount is invalid.'));
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');
        $cents = ((int) $whole * 100) + (int) $fraction;
        if ($cents <= 0) {
            throw new PaymentCheckoutException('WEBHOOK_AMOUNT_INVALID', 400, __('The payment callback amount is invalid.'));
        }

        return $cents;
    }

    private function finishedAt(mixed $value): CarbonImmutable
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            throw new PaymentCheckoutException('FINISH_TIME_MISSING', 409, __('The payment finish time is unavailable.'));
        }
        try {
            $hasOffset = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $raw) === 1;
            $finishedAt = $hasOffset
                ? CarbonImmutable::parse($raw)->utc()
                : CarbonImmutable::parse($raw, 'Asia/Qatar')->utc();
        } catch (\Throwable) {
            throw new PaymentCheckoutException('FINISH_TIME_INVALID', 409, __('The payment finish time is invalid.'));
        }
        if ($finishedAt->greaterThan(now('UTC')->addMinutes(5))) {
            throw new PaymentCheckoutException('FINISH_TIME_FUTURE', 409, __('The payment finish time is invalid.'));
        }

        return $finishedAt;
    }

    private function normalizedStatus(string $statusId): string
    {
        return match ($statusId) {
            '0', '1', '12' => 'pending',
            '2' => 'paid',
            '3', '4', '5' => 'unpaid_terminal',
            '6', '7', '8' => 'reversal_exception',
            default => 'unknown',
        };
    }
}

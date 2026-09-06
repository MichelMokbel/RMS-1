<?php

namespace App\Jobs;

use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\PaymentProviderTransaction;
use App\Services\Payments\SkipCashProvider;
use App\Services\Promotions\MembershipPromotionReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class InitiateSkipCashCheckout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public function __construct(
        public readonly int $attemptId,
    ) {
        $this->timeout = (int) config('payments.skipcash.worker_timeout_seconds', 30);
    }

    public function handle(
        SkipCashProvider $provider,
        MembershipPromotionReservationService $promotionReservations,
    ): void {
        $attempt = DB::transaction(function () use ($promotionReservations): ?PaymentCheckoutAttempt {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($this->attemptId);
            if (! $attempt || $attempt->provider_create_outcome !== 'not_sent' || ! (bool) config('payments.skipcash.enabled', false)) {
                return null;
            }
            if ($attempt->expires_at?->isPast()) {
                $attempt->update([
                    'state' => 'expired',
                    'last_error_code' => 'EXPIRED_BEFORE_DISPATCH',
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
                $promotionReservations->releaseForCheckout(
                    (int) $attempt->id,
                    'expired_before_dispatch',
                );

                return null;
            }

            $attempt->update([
                'state' => 'pending',
                'provider_create_outcome' => 'in_flight',
                'provider_dispatched_at' => now('UTC'),
                'last_error_code' => null,
            ]);

            return $attempt->fresh();
        });

        if (! $attempt) {
            return;
        }

        $snapshot = $attempt->customer_snapshot;
        $request = [
            'Uid' => $attempt->provider_request_uuid,
            'KeyId' => (string) config('payments.skipcash.key_id'),
            'Amount' => $this->amount((int) $attempt->payable_amount_cents),
            'FirstName' => (string) ($snapshot['first_name'] ?? ''),
            'LastName' => (string) ($snapshot['last_name'] ?? ''),
            'Phone' => (string) ($snapshot['phone'] ?? ''),
            'Email' => (string) ($snapshot['email'] ?? ''),
            'TransactionId' => str_replace('-', '', (string) $attempt->reference),
            'ReturnUrl' => $this->returnUrl((string) config('payments.skipcash.return_url'), (string) $attempt->reference),
            'WebhookUrl' => (string) config('payments.skipcash.webhook_url'),
        ];

        try {
            $result = $provider->create($request);
        } catch (\Throwable $exception) {
            $this->markUnknown();

            return;
        }

        if (($result['outcome'] ?? null) !== 'created' || ! $this->validCreateResult($result)) {
            DB::transaction(function () use ($promotionReservations): void {
                $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($this->attemptId);
                if (! $attempt || $attempt->provider_create_outcome !== 'in_flight') {
                    return;
                }
                $attempt->update([
                    'state' => 'declined',
                    'provider_create_outcome' => 'rejected',
                    'last_error_code' => 'PROVIDER_CREATE_REJECTED',
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
                $promotionReservations->releaseForCheckout(
                    (int) $attempt->id,
                    'provider_create_rejected',
                );
            });

            return;
        }

        DB::transaction(function () use ($result): void {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->findOrFail($this->attemptId);
            if ($attempt->provider_create_outcome !== 'in_flight') {
                return;
            }
            $payUrl = (string) $result['pay_url'];
            if (! $this->allowedPayUrl($payUrl)) {
                $attempt->update([
                    'provider_create_outcome' => 'unknown',
                    'last_error_code' => 'PROVIDER_PAY_URL_INVALID',
                ]);

                return;
            }

            PaymentProviderTransaction::query()->create([
                'attempt_id' => $attempt->id,
                'payment_source_id' => $attempt->payment_source_id,
                'provider_payment_id' => (string) $result['provider_payment_id'],
                'merchant_transaction_id' => (string) $result['merchant_transaction_id'],
                'amount_cents' => (int) $attempt->payable_amount_cents,
                'currency' => 'QAR',
                'raw_status' => (string) ($result['raw_status'] ?? ''),
                'normalized_status' => 'pending',
                'pay_url' => $payUrl,
                'classification' => 'pending',
            ]);
            $attempt->update([
                'provider_create_outcome' => 'created',
                'next_recovery_at' => now('UTC')->addMinutes((int) config('payments.skipcash.detail_recheck_minutes', 5)),
            ]);
        });
    }

    /** @param array<string, mixed> $result */
    private function validCreateResult(array $result): bool
    {
        return trim((string) ($result['provider_payment_id'] ?? '')) !== ''
            && trim((string) ($result['merchant_transaction_id'] ?? '')) !== ''
            && trim((string) ($result['pay_url'] ?? '')) !== '';
    }

    private function allowedPayUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && $host !== ''
            && in_array($host, (array) config('payments.skipcash.pay_url_hosts', []), true);
    }

    private function returnUrl(string $url, string $reference): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'checkout_reference='.rawurlencode($reference);
    }

    private function amount(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function markUnknown(): void
    {
        DB::transaction(function (): void {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->find($this->attemptId);
            if ($attempt && $attempt->provider_create_outcome === 'in_flight') {
                $attempt->update([
                    'provider_create_outcome' => 'unknown',
                    'last_error_code' => 'PROVIDER_CREATE_UNKNOWN',
                    'next_recovery_at' => null,
                ]);
            }
        });
    }
}

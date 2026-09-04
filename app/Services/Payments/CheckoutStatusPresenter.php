<?php

namespace App\Services\Payments;

use App\Models\PaymentCheckoutAttempt;

class CheckoutStatusPresenter
{
    /** @return array<string, mixed> */
    public function present(PaymentCheckoutAttempt $attempt, bool $detail = false): array
    {
        $attempt->loadMissing('targets.invoice');
        $state = (string) $attempt->state;
        $status = match ($state) {
            'paid_processing' => 'paid_processing',
            'completed' => 'completed',
            'initiating', 'pending' => 'pending',
            default => 'declined',
        };
        $financialIntent = is_array($attempt->financial_intent) ? $attempt->financial_intent : [];
        $payUrl = null;
        if (in_array((string) $attempt->provider_create_outcome, ['created'], true)
            && $status === 'pending'
            && ! $attempt->expires_at?->isPast()) {
            $payUrl = $attempt->providerTransactions()->latest('id')->first()?->pay_url;
        }
        $confirmedTargets = $attempt->targets
            ->filter(fn ($target): bool => $target->invoice_id !== null && $target->order_id !== null)
            ->map(fn ($target): array => [
                'order_id' => (int) $target->order_id,
                'invoice_id' => (int) $target->invoice_id,
                'invoice_number' => $target->invoice?->invoice_number,
                'service_date' => $target->service_date?->toDateString(),
                'total_amount_cents' => (int) $target->expected_amount_cents,
                'invoice_status' => $target->invoice?->status,
                'invoice_balance_cents' => $target->invoice?->balance_cents,
            ])
            ->values()
            ->all();

        $result = [
            'reference' => $attempt->reference,
            'status' => $status,
            'purchase_confirmed' => $state === 'completed',
            'message' => $this->message($status),
            'recovery_reference' => $attempt->reference,
            'currency' => $attempt->currency,
            'payable_amount_cents' => (int) $attempt->payable_amount_cents,
            'paid_amount_cents' => $financialIntent['provider_transaction_id'] ?? null ? (int) $attempt->payable_amount_cents : 0,
            'confirmed_amount_cents' => $state === 'completed' ? (int) $attempt->payable_amount_cents : 0,
            'retained_credit_amount_cents' => 0,
            'expires_at' => $attempt->expires_at?->toIso8601String(),
            'pay_url' => $payUrl,
            'confirmed_targets' => $confirmedTargets,
        ];

        if ($detail) {
            $result['reviewed_cart'] = $attempt->cart_snapshot['cart'] ?? null;
            $result['support_phone'] = $attempt->terms_snapshot['support_phone'] ?? null;
        }

        return $result;
    }

    private function message(string $status): string
    {
        return match ($status) {
            'completed' => __('Your payment and order are confirmed.'),
            'paid_processing' => __('Your payment is being confirmed.'),
            'declined' => __('This checkout did not complete.'),
            default => __('Your checkout is ready for payment.'),
        };
    }
}

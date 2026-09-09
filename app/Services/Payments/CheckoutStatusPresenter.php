<?php

namespace App\Services\Payments;

use App\Models\PaymentCheckoutAttempt;
use App\Services\Subscriptions\MembershipQueueService;

class CheckoutStatusPresenter
{
    public function __construct(
        private readonly MembershipQueueService $membershipQueues,
    ) {}

    /** @return array<string, mixed> */
    public function present(PaymentCheckoutAttempt $attempt, bool $detail = false): array
    {
        $attempt->loadMissing('targets.invoice', 'targets.mealPlanRequest');
        $state = (string) $attempt->state;
        $status = match ($state) {
            'paid_processing' => 'paid_processing',
            'completed', 'payment_received_as_credit' => 'completed',
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
            'message' => $this->message($attempt, $status),
            'recovery_reference' => $attempt->reference,
            'currency' => $attempt->currency,
            'gross_amount_cents' => (int) $attempt->gross_amount_cents,
            'discount_amount_cents' => (int) $attempt->discount_amount_cents,
            'payable_amount_cents' => (int) $attempt->payable_amount_cents,
            'paid_amount_cents' => $financialIntent['provider_transaction_id'] ?? null ? (int) $attempt->payable_amount_cents : 0,
            'confirmed_amount_cents' => $state === 'completed' ? (int) $attempt->payable_amount_cents : 0,
            'retained_credit_amount_cents' => $state === 'payment_received_as_credit'
                ? (int) ($attempt->providerTransactions()->whereNotNull('payment_id')->latest('id')->first()?->payment?->unallocatedCents() ?? 0)
                : 0,
            'expires_at' => $attempt->expires_at?->toIso8601String(),
            'pay_url' => $payUrl,
            'confirmed_targets' => $confirmedTargets,
        ];

        if ($attempt->purpose === 'membership') {
            $notification = is_array($attempt->notification_snapshots) ? $attempt->notification_snapshots : [];
            $request = $attempt->targets->first()?->mealPlanRequest;
            $result['meal_plan_request_id'] = $request?->id;
            $result['subscription_id'] = $request?->converted_subscription_id;
            $result['purchase_block_id'] = $notification['purchase_block_id'] ?? null;
            $result['result_kind'] = 'paid_membership';
            $result['promotion'] = $attempt->pricing_snapshot['promotion'] ?? null;
            $result['queue'] = $request?->converted_subscription_id
                ? $this->membershipQueues->summary(
                    (int) $attempt->customer_id,
                    (int) $attempt->company_id,
                    (int) $attempt->branch_id,
                    (string) $attempt->currency,
                )
                : null;
        }
        if ($attempt->purpose === 'membership_booking') {
            $result['result_kind'] = 'covered_booking_with_add_ons';
            $result['queue'] = $state === 'completed'
                ? $this->membershipQueues->summary(
                    (int) $attempt->customer_id,
                    (int) $attempt->company_id,
                    (int) $attempt->branch_id,
                    (string) $attempt->currency,
                )
                : null;
        }

        if ($detail) {
            $result['reviewed_cart'] = $attempt->cart_snapshot['cart'] ?? $attempt->cart_snapshot;
            $result['support_phone'] = $attempt->terms_snapshot['support_phone'] ?? null;
        }

        return $result;
    }

    private function message(PaymentCheckoutAttempt $attempt, string $status): string
    {
        if ($attempt->purpose === 'membership') {
            if ($attempt->state === 'payment_received_as_credit') {
                return __('Your payment was received as customer credit, but the membership was not activated because payment finished after the checkout window.');
            }

            return match ($status) {
                'completed' => __('Your membership payment is complete and your meal allowance is ready.'),
                'paid_processing' => __('Your membership payment is being confirmed.'),
                'declined' => __('This membership checkout did not complete.'),
                default => __('Your membership checkout is ready for payment.'),
            };
        }

        if ($attempt->purpose === 'membership_booking') {
            if ($attempt->state === 'payment_received_as_credit') {
                return __('Your add-on payment was received as customer credit. No membership meals were booked because the checkout could not be completed.');
            }

            return match ($status) {
                'completed' => __('Your membership meals and paid add-ons are confirmed.'),
                'paid_processing' => __('Your add-on payment is being confirmed.'),
                'declined' => __('This add-on checkout did not complete. Your meal hold was released.'),
                default => __('Your membership add-on checkout is ready for payment.'),
            };
        }

        return match ($status) {
            'completed' => __('Your payment and order are confirmed.'),
            'paid_processing' => __('Your payment is being confirmed.'),
            'declined' => __('This checkout did not complete.'),
            default => __('Your checkout is ready for payment.'),
        };
    }
}

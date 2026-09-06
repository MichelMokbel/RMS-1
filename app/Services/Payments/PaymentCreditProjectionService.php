<?php

namespace App\Services\Payments;

use App\Models\ArInvoice;
use App\Models\MealSubscription;
use App\Models\MembershipBookingFunding;
use App\Models\MembershipPurchaseBlock;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Builder;

class PaymentCreditProjectionService
{
    /**
     * @return array{
     *     state:string,
     *     reason_code:string|null,
     *     amount_cents:int,
     *     allocated_cents:int,
     *     unallocated_cents:int,
     *     committed_cents:int,
     *     available_cents:int,
     *     committed_subscription_ids:array<int, int>
     * }
     */
    public function project(Payment $payment, bool $lock = false): array
    {
        $allocations = PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->whereNull('voided_at')
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->get(['id', 'amount_cents']);
        $allocated = (int) $allocations->sum('amount_cents');
        $amount = (int) $payment->amount_cents;
        $unallocated = $amount - $allocated;

        if ($payment->source !== 'ar' || $payment->voided_at || $amount <= 0 || $allocated < 0 || $unallocated < 0) {
            return $this->unavailable($amount, $allocated, max(0, $unallocated), 'PAYMENT_CREDIT_INCONSISTENT');
        }

        $subscriptions = MealSubscription::query()
            ->where('source_payment_id', $payment->id)
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->orderBy('id')
            ->get();
        $committedSubscriptionIds = [];

        foreach ($subscriptions as $subscription) {
            if ((int) $subscription->customer_id !== (int) $payment->customer_id
                || (int) $subscription->branch_id !== (int) $payment->branch_id) {
                return $this->unavailable($amount, $allocated, $unallocated, 'MEMBERSHIP_FUNDING_SCOPE_MISMATCH');
            }

            if ($subscription->status === 'cancelled') {
                continue;
            }

            $total = $subscription->plan_meals_total;
            $used = (int) $subscription->meals_used;
            if (! $subscription->uses_invoice_tracking || $used < 0 || ($total !== null && (int) $total <= 0)) {
                return $this->unavailable($amount, $allocated, $unallocated, 'MEMBERSHIP_FUNDING_INCONSISTENT');
            }

            if (in_array($subscription->status, ['active', 'paused'], true)) {
                if ($total !== null && $used >= (int) $total) {
                    return $this->unavailable($amount, $allocated, $unallocated, 'MEMBERSHIP_FUNDING_INCONSISTENT');
                }
                $committedSubscriptionIds[] = (int) $subscription->id;

                continue;
            }

            if ($subscription->status === 'expired') {
                if ($total === null || $used < (int) $total) {
                    return $this->unavailable($amount, $allocated, $unallocated, 'MEMBERSHIP_FUNDING_INCONSISTENT');
                }

                continue;
            }

            return $this->unavailable($amount, $allocated, $unallocated, 'MEMBERSHIP_FUNDING_INCONSISTENT');
        }

        $blocks = MembershipPurchaseBlock::query()
            ->where('payment_id', $payment->id)
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->get();
        foreach ($blocks as $block) {
            if ((int) $block->company_id !== (int) $payment->company_id
                || (int) $block->branch_id !== (int) $payment->branch_id
                || (int) $block->original_customer_id !== (int) $payment->customer_id
                || $block->currency !== $payment->currency) {
                return $this->unavailable($amount, $allocated, $unallocated, 'MEMBERSHIP_FUNDING_SCOPE_MISMATCH');
            }
            if (! $block->cancelled_at) {
                $committedSubscriptionIds[] = (int) $block->subscription_id;
            }
        }

        $committedSubscriptionIds = array_values(array_unique($committedSubscriptionIds));

        $committed = $committedSubscriptionIds === [] ? 0 : $unallocated;

        return [
            'state' => $committed > 0 ? 'committed' : 'available',
            'reason_code' => null,
            'amount_cents' => $amount,
            'allocated_cents' => $allocated,
            'unallocated_cents' => $unallocated,
            'committed_cents' => $committed,
            'available_cents' => $unallocated - $committed,
            'committed_subscription_ids' => $committedSubscriptionIds,
        ];
    }

    public function committedAvailableForInvoice(Payment $payment, ArInvoice $invoice, bool $lock = false): int
    {
        $projection = $this->project($payment, $lock);
        if ($projection['state'] !== 'committed' || $projection['unallocated_cents'] <= 0) {
            return 0;
        }

        $queueCommitted = MembershipBookingFunding::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('state', ['reserved', 'invoiced'])
            ->whereHas('purchaseBlock', fn (Builder $query) => $query
                ->where('payment_id', $payment->id)
                ->whereNull('cancelled_at'))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->sum('invoice_net_cents');
        if ((int) $queueCommitted > 0) {
            return min((int) $queueCommitted, (int) $projection['unallocated_cents']);
        }

        $invoice->loadMissing('items');
        $invoiceSubscriptionIds = $invoice->items
            ->filter(fn ($item): bool => (bool) ($item->meta['is_subscription'] ?? false)
                && isset($item->meta['subscription_id']))
            ->pluck('meta.subscription_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($invoiceSubscriptionIds !== [] && MealSubscription::query()
            ->whereIn('id', $invoiceSubscriptionIds)
            ->where('fulfillment_mode', 'customer_selection')
            ->exists()) {
            return 0;
        }

        return array_intersect($projection['committed_subscription_ids'], $invoiceSubscriptionIds) !== []
            ? $projection['unallocated_cents']
            : 0;
    }

    /** @return array<string, mixed> */
    private function unavailable(int $amount, int $allocated, int $unallocated, string $reasonCode): array
    {
        return [
            'state' => 'unavailable',
            'reason_code' => $reasonCode,
            'amount_cents' => $amount,
            'allocated_cents' => $allocated,
            'unallocated_cents' => $unallocated,
            'committed_cents' => 0,
            'available_cents' => 0,
            'committed_subscription_ids' => [],
        ];
    }
}

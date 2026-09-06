<?php

namespace App\Services\Subscriptions;

use App\Models\ArInvoice;
use App\Models\MealSubscription;
use App\Models\MembershipBookingFunding;
use App\Models\MembershipPurchaseBlock;
use App\Models\PaymentAllocation;
use App\Services\Customers\CustomerOwnershipService;
use App\Services\Payments\PaymentConsistencyDispatchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MembershipBookingFundingService
{
    public function __construct(
        private readonly CustomerOwnershipService $customerOwnership,
        private readonly MembershipQueueService $queues,
        private readonly PaymentConsistencyDispatchService $paymentConsistency,
    ) {}

    /**
     * Acquire the queue locks before a linked invoice or its allocations are
     * locked by a direct AR correction entry point.
     */
    public function lockQueueForInvoiceMutation(int $invoiceId): bool
    {
        $seed = MembershipBookingFunding::query()
            ->with('purchaseBlock')
            ->where('invoice_id', $invoiceId)
            ->orderBy('id')
            ->first();
        if (! $seed) {
            return false;
        }

        $blocks = $this->lockQueueForBlock($seed->purchaseBlock);
        $affectedRows = MembershipBookingFunding::query()
            ->where('invoice_id', $invoiceId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($affectedRows->isEmpty()
            || $affectedRows->contains(fn (MembershipBookingFunding $row): bool => ! $blocks->contains('id', (int) $row->purchase_block_id))) {
            throw new \RuntimeException('Membership invoice funding changed while it was being locked.');
        }

        return true;
    }

    /**
     * Serialize administrator allocation attempts with a payment's active
     * membership queue before the payment itself is locked.
     */
    public function lockQueueForPaymentMutation(int $paymentId): bool
    {
        $seed = MembershipPurchaseBlock::query()
            ->where('payment_id', $paymentId)
            ->whereNull('cancelled_at')
            ->orderBy('id')
            ->first();
        if (! $seed) {
            return false;
        }

        $blocks = $this->lockQueueForBlock($seed);
        if (! $blocks->contains(fn (MembershipPurchaseBlock $block): bool => (int) $block->payment_id === $paymentId && $block->cancelled_at === null)) {
            throw new \RuntimeException('Membership payment funding changed while it was being locked.');
        }

        return true;
    }

    /** @return Collection<int, MealSubscription> */
    public function lockQueueForSubscriptionMutation(MealSubscription $subscription): Collection
    {
        if ($subscription->fulfillment_mode !== 'customer_selection'
            || ! $subscription->queue_company_id
            || ! $subscription->branch_id) {
            return collect();
        }

        $customer = $this->customerOwnership->lockCanonicalCustomer((int) $subscription->customer_id);
        $roots = $this->queues->lockCompatibleRoots(
            $customer->id,
            (int) $subscription->queue_company_id,
            (int) $subscription->branch_id,
            (string) ($subscription->queue_currency ?: 'QAR'),
        );
        if (! $roots->contains('id', (int) $subscription->id)) {
            throw new \RuntimeException('Membership subscription is outside its owned queue.');
        }
        $blocks = MembershipPurchaseBlock::query()
            ->whereIn('subscription_id', $roots->pluck('id'))
            ->orderBy('funded_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        MembershipBookingFunding::query()
            ->whereIn('purchase_block_id', $blocks->pluck('id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return $roots;
    }

    /** @return Collection<int, MembershipPurchaseBlock> */
    private function lockQueueForBlock(MembershipPurchaseBlock $block): Collection
    {
        $customer = $this->customerOwnership->lockCanonicalCustomer((int) $block->original_customer_id);
        $roots = $this->queues->lockCompatibleRoots(
            $customer->id,
            (int) $block->company_id,
            (int) $block->branch_id,
            (string) $block->currency,
        );
        if ($roots->isEmpty() || ! $roots->contains('id', (int) $block->subscription_id)) {
            throw new \RuntimeException('Membership invoice funding is outside its owned queue.');
        }

        $blocks = MembershipPurchaseBlock::query()
            ->whereIn('subscription_id', $roots->pluck('id'))
            ->orderBy('funded_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        MembershipBookingFunding::query()
            ->whereIn('purchase_block_id', $blocks->pluck('id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return $blocks;
    }

    /**
     * The caller must already hold the canonical customer and queue root locks.
     *
     * @param  Collection<int, MealSubscription>  $roots
     * @return array<int, array{block:MembershipPurchaseBlock,positions:array<int,int>,position_ranges:array<int,array{0:int,1:int}>,main_quantity:int,gross_cents:int,discount_cents:int,net_cents:int}>
     */
    public function reserveOldestPositions(Collection $roots, int $quantity): array
    {
        if ($quantity <= 0) {
            throw new \RuntimeException('Membership booking quantity must be positive.');
        }

        $blocks = MembershipPurchaseBlock::query()
            ->whereIn('subscription_id', $roots->pluck('id'))
            ->whereNull('cancelled_at')
            ->orderBy('funded_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $funding = MembershipBookingFunding::query()
            ->whereIn('purchase_block_id', $blocks->pluck('id'))
            ->whereIn('state', ['reserved', 'invoiced'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->groupBy('purchase_block_id');

        $remaining = $quantity;
        $result = [];
        foreach ($blocks as $block) {
            if ($remaining <= 0) {
                break;
            }

            $occupied = [];
            foreach ($this->positionsFromRanges((array) $block->opening_used_ranges) as $position) {
                $occupied[$position] = true;
            }
            foreach ($this->positionsFromRanges((array) $block->opening_released_ranges) as $position) {
                unset($occupied[$position]);
            }
            foreach ($funding->get($block->id, collect()) as $row) {
                foreach ($this->positionsFromRanges((array) $row->position_ranges) as $position) {
                    $occupied[$position] = true;
                }
            }

            $positions = [];
            for ($position = 1; $position <= (int) $block->meal_count && count($positions) < $remaining; $position++) {
                if (! isset($occupied[$position])) {
                    $positions[] = $position;
                }
            }
            if ($positions === []) {
                continue;
            }

            $totals = $this->totalsForPositions($block, $positions);
            $result[] = [
                'block' => $block,
                'positions' => $positions,
                'position_ranges' => $this->compactRanges($positions),
                'main_quantity' => count($positions),
                ...$totals,
            ];
            $remaining -= count($positions);
        }

        if ($remaining > 0) {
            throw new \RuntimeException('The membership does not have enough available meals.');
        }

        return $result;
    }

    public function transitionIssuedInvoice(ArInvoice $invoice, int $actorId): bool
    {
        $transitioned = DB::transaction(function () use ($invoice): bool {
            $rows = MembershipBookingFunding::query()
                ->with(['purchaseBlock', 'subscriptionOrder'])
                ->where('invoice_id', $invoice->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($rows->isEmpty()) {
                return false;
            }
            if ($rows->contains(fn (MembershipBookingFunding $row): bool => $row->state === 'released')) {
                throw new \RuntimeException('Released membership funding cannot be invoiced again.');
            }

            $allocations = PaymentAllocation::query()
                ->where('allocatable_type', ArInvoice::class)
                ->where('allocatable_id', $invoice->id)
                ->whereNull('voided_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('payment_id');

            foreach ($rows->groupBy(fn (MembershipBookingFunding $row): int => (int) $row->purchaseBlock->payment_id) as $paymentId => $paymentRows) {
                $expected = (int) $paymentRows->sum('invoice_net_cents');
                $allocation = $allocations->get($paymentId);
                if ($expected > 0 && (! $allocation || (int) $allocation->amount_cents !== $expected)) {
                    throw new \RuntimeException('Membership invoice allocation does not match its funding.');
                }
                if ($expected === 0 && $allocation) {
                    throw new \RuntimeException('Zero value membership funding cannot have a payment allocation.');
                }
                foreach ($paymentRows as $row) {
                    if ($row->state === 'invoiced') {
                        continue;
                    }
                    $row->update([
                        'state' => 'invoiced',
                        'invoiced_at' => now('UTC'),
                        'payment_allocation_id' => $allocation?->id,
                    ]);
                }
            }

            foreach ($rows->groupBy(fn (MembershipBookingFunding $row): int => (int) $row->purchaseBlock->subscription_id) as $subscriptionId => $subscriptionRows) {
                $newlyInvoiced = $subscriptionRows
                    ->filter(fn (MembershipBookingFunding $row): bool => $row->wasChanged('state'))
                    ->sum('main_quantity');
                if ($newlyInvoiced <= 0) {
                    continue;
                }
                $subscription = MealSubscription::query()->lockForUpdate()->findOrFail($subscriptionId);
                if ($subscription->fulfillment_mode !== 'customer_selection') {
                    throw new \RuntimeException('Membership funding points to an incompatible subscription.');
                }
                $used = (int) $subscription->meals_used + (int) $newlyInvoiced;
                if ($used > (int) $subscription->plan_meals_total) {
                    throw new \RuntimeException('Membership invoice would exceed the funded allowance.');
                }
                $subscription->update([
                    'meals_used' => $used,
                    'queue_revision' => (int) $subscription->queue_revision + 1,
                ]);
            }

            return true;
        });

        if ($transitioned) {
            $this->dispatchInvoiceGraph($invoice->id, 'issued');
        }

        return $transitioned;
    }

    public function releaseVoidedInvoice(ArInvoice $invoice, int $actorId): bool
    {
        $released = DB::transaction(function () use ($invoice, $actorId): bool {
            $rows = MembershipBookingFunding::query()
                ->with(['purchaseBlock', 'subscriptionOrder.order'])
                ->where('invoice_id', $invoice->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($rows->isEmpty()) {
                return false;
            }

            foreach ($rows->where('state', 'invoiced')->groupBy(fn (MembershipBookingFunding $row): int => (int) $row->purchaseBlock->subscription_id) as $subscriptionId => $subscriptionRows) {
                $subscription = MealSubscription::query()->lockForUpdate()->findOrFail($subscriptionId);
                $subscription->update([
                    'meals_used' => max(0, (int) $subscription->meals_used - (int) $subscriptionRows->sum('main_quantity')),
                    'queue_revision' => (int) $subscription->queue_revision + 1,
                    'status' => 'active',
                    'end_date' => null,
                ]);
            }

            $releasedAt = now('UTC');
            foreach ($rows as $row) {
                if ($row->state !== 'released') {
                    $row->update([
                        'state' => 'released',
                        'released_at' => $releasedAt,
                        'released_by' => $actorId,
                    ]);
                }
                $order = $row->subscriptionOrder?->order;
                if ($order && $order->status !== 'Cancelled') {
                    $order->update(['status' => 'Cancelled']);
                }
            }

            return true;
        });

        if ($released) {
            $this->dispatchInvoiceGraph($invoice->id, 'voided');
        }

        return $released;
    }

    private function dispatchInvoiceGraph(int $invoiceId, string $transition): void
    {
        MembershipBookingFunding::query()
            ->where('invoice_id', $invoiceId)
            ->orderBy('id')
            ->get(['id', 'purchase_block_id', 'subscription_order_id'])
            ->each(function (MembershipBookingFunding $row) use ($invoiceId, $transition): void {
                $subscriptionId = MembershipPurchaseBlock::query()
                    ->whereKey($row->purchase_block_id)
                    ->value('subscription_id');
                if ($subscriptionId) {
                    $this->paymentConsistency->bookingAfterCommit(
                        (int) $row->subscription_order_id,
                        (int) $subscriptionId,
                        'ar_invoice',
                        $invoiceId,
                        $transition,
                    );
                }
            });
    }

    /** @return array{gross_cents:int,discount_cents:int,net_cents:int} */
    private function totalsForPositions(MembershipPurchaseBlock $block, array $positions): array
    {
        $gross = 0;
        $discount = 0;
        foreach ($positions as $position) {
            $currentGross = intdiv((int) $block->gross_price_cents * $position, (int) $block->meal_count);
            $previousGross = intdiv((int) $block->gross_price_cents * ($position - 1), (int) $block->meal_count);
            $grossSlice = $currentGross - $previousGross;
            $currentDiscount = intdiv((int) $block->discount_cents * $currentGross, (int) $block->gross_price_cents);
            $previousDiscount = intdiv((int) $block->discount_cents * $previousGross, (int) $block->gross_price_cents);
            $discountSlice = $currentDiscount - $previousDiscount;
            if ($grossSlice < 0 || $discountSlice < 0 || $discountSlice > $grossSlice) {
                throw new \RuntimeException('Membership funding apportionment is inconsistent.');
            }
            $gross += $grossSlice;
            $discount += $discountSlice;
        }

        return [
            'gross_cents' => $gross,
            'discount_cents' => $discount,
            'net_cents' => $gross - $discount,
        ];
    }

    /** @return array<int, int> */
    private function positionsFromRanges(array $ranges): array
    {
        $positions = [];
        foreach ($ranges as $range) {
            if (! is_array($range) || count($range) !== 2) {
                throw new \RuntimeException('Membership position ranges are invalid.');
            }
            $start = (int) $range[0];
            $end = (int) $range[1];
            if ($start <= 0 || $end < $start) {
                throw new \RuntimeException('Membership position ranges are invalid.');
            }
            for ($position = $start; $position <= $end; $position++) {
                $positions[] = $position;
            }
        }

        return $positions;
    }

    /** @return array<int, array{0:int,1:int}> */
    private function compactRanges(array $positions): array
    {
        sort($positions, SORT_NUMERIC);
        $ranges = [];
        foreach ($positions as $position) {
            $last = count($ranges) - 1;
            if ($last >= 0 && $ranges[$last][1] + 1 === $position) {
                $ranges[$last][1] = $position;
            } else {
                $ranges[] = [$position, $position];
            }
        }

        return $ranges;
    }
}

<?php

namespace App\Listeners;

use App\Events\InvoiceIssued;
use App\Models\MealSubscription;
use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;

class SyncSubscriptionMealsOnInvoiceIssued
{
    public function handle(InvoiceIssued $event): void
    {
        $invoice = $event->invoice->loadMissing('items');

        // --- Path A: auto-generated subscription orders invoiced via buildDailyDishInvoiceItems ---
        // Items tagged with meta.is_subscription = true and meta.subscription_id
        $subIds = $invoice->items
            ->filter(fn ($item) => ($item->meta['is_subscription'] ?? false) && isset($item->meta['subscription_id']))
            ->pluck('meta.subscription_id')
            ->unique()
            ->filter()
            ->values();

        foreach ($subIds as $subId) {
            $this->incrementMealsUsed((int) $subId, 1);
        }

        // --- Path B: manually created invoices with MI-000084 / MI-000094 as real sellable items ---
        // Only increment the subscription whose source_payment_id is one of the payments that
        // allocated this invoice. This prevents unrelated historic purchases from being counted.
        $planItemIds = array_values(config('subscriptions.plan_menu_item_ids', []));
        if (! empty($planItemIds) && $invoice->customer_id) {
            $planItems = $invoice->items->filter(
                fn ($item) => $item->sellable_type === MenuItem::class
                    && in_array((int) $item->sellable_id, $planItemIds, true)
            );

            if ($planItems->isNotEmpty()) {
                // Resolve which payments allocated this invoice (auto-allocation already ran before dispatch).
                $invoice->loadMissing('paymentAllocations');
                $allocatedPaymentIds = $invoice->paymentAllocations
                    ->pluck('payment_id')
                    ->filter()
                    ->unique()
                    ->values();

                $sub = null;
                if ($allocatedPaymentIds->isNotEmpty()) {
                    $sub = MealSubscription::where('customer_id', $invoice->customer_id)
                        ->where('uses_invoice_tracking', true)
                        ->whereIn('status', ['active', 'paused'])
                        ->whereIn('source_payment_id', $allocatedPaymentIds)
                        ->orderByDesc('start_date')
                        ->first();
                }

                if ($sub) {
                    $mealCount = (int) $planItems->sum(fn ($item) => (float) ($item->qty ?? 1));
                    if ($mealCount > 0) {
                        $this->incrementMealsUsed($sub->id, $mealCount);
                    }
                }
            }
        }
    }

    private function incrementMealsUsed(int $subId, int $count): void
    {
        DB::transaction(function () use ($subId, $count) {
            $sub = MealSubscription::lockForUpdate()->find($subId);

            if (! $sub || ! $sub->uses_invoice_tracking || $sub->plan_meals_total === null) {
                return;
            }

            $sub->meals_used = (int) ($sub->meals_used ?? 0) + $count;

            if ($sub->meals_used >= $sub->plan_meals_total) {
                $sub->status   = 'expired';
                $sub->end_date = $sub->end_date ?? now()->toDateString();
            }

            $sub->save();
        });
    }
}

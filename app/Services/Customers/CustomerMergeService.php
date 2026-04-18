<?php

namespace App\Services\Customers;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerMergeService
{
    /**
     * Move all data from $source into $target, then deactivate $source.
     *
     * Tables reassigned: orders, meal_subscriptions, ar_invoices, payments,
     * sales, pastry_orders, meal_plan_requests,
     * customer_phone_verification_challenges, users (with conflict handling).
     */
    public function merge(Customer $source, Customer $target, int $actorId): void
    {
        if ($source->id === $target->id) {
            throw ValidationException::withMessages([
                'target' => __('Source and target customers must be different.'),
            ]);
        }

        DB::transaction(function () use ($source, $target, $actorId): void {
            $sourceId = $source->id;
            $targetId = $target->id;

            // Simple bulk reassignments — no unique constraints
            DB::table('orders')->where('customer_id', $sourceId)->update(['customer_id' => $targetId]);
            DB::table('meal_subscriptions')->where('customer_id', $sourceId)->update(['customer_id' => $targetId]);
            DB::table('ar_invoices')->where('customer_id', $sourceId)->update(['customer_id' => $targetId]);
            DB::table('payments')->where('customer_id', $sourceId)->update(['customer_id' => $targetId]);
            DB::table('sales')->where('customer_id', $sourceId)->update(['customer_id' => $targetId]);
            DB::table('pastry_orders')->where('customer_id', $sourceId)->update(['customer_id' => $targetId]);
            DB::table('meal_plan_requests')->where('customer_id', $sourceId)->update(['customer_id' => $targetId]);
            DB::table('customer_phone_verification_challenges')
                ->where('customer_id', $sourceId)
                ->update(['customer_id' => $targetId]);

            // Portal user: users.customer_id has a UNIQUE constraint.
            // If target already has a portal user, deactivate the source user instead of moving it.
            // If target has no portal user, transfer the source user.
            $targetHasUser = DB::table('users')->where('customer_id', $targetId)->exists();
            if ($targetHasUser) {
                DB::table('users')
                    ->where('customer_id', $sourceId)
                    ->update(['is_active' => false, 'customer_id' => null]);
            } else {
                DB::table('users')
                    ->where('customer_id', $sourceId)
                    ->update(['customer_id' => $targetId]);
            }

            // Deactivate and annotate the source customer
            $mergeNote = 'Merged into: '.$target->name.' (ID '.$targetId.') by user '.$actorId.' on '.now()->toDateTimeString();
            $existingNotes = $source->notes ? $source->notes."\n".$mergeNote : $mergeNote;

            DB::table('customers')->where('id', $sourceId)->update([
                'is_active' => false,
                'notes'     => $existingNotes,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Return a count summary of records that will be moved from $source.
     */
    public function summary(Customer $source): array
    {
        $id = $source->id;

        return [
            'invoices'      => DB::table('ar_invoices')->where('customer_id', $id)->count(),
            'payments'      => DB::table('payments')->where('customer_id', $id)->count(),
            'orders'        => DB::table('orders')->where('customer_id', $id)->count(),
            'subscriptions' => DB::table('meal_subscriptions')->where('customer_id', $id)->count(),
            'sales'         => DB::table('sales')->where('customer_id', $id)->count(),
            'pastry_orders' => DB::table('pastry_orders')->where('customer_id', $id)->count(),
        ];
    }
}

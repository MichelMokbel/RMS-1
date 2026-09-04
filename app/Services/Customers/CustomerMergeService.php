<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerMergeService
{
    public function __construct(
        private readonly AccountingAuditLogService $auditLog,
    ) {}

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
            $lockedCustomers = Customer::query()
                ->whereIn('id', [$sourceId, $targetId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedSource = $lockedCustomers->get($sourceId);
            $lockedTarget = $lockedCustomers->get($targetId);
            if (! $lockedSource || ! $lockedTarget) {
                throw ValidationException::withMessages([
                    'target' => __('The selected customer could not be found.'),
                ]);
            }
            if ($lockedSource->merged_into_customer_id !== null) {
                if ((int) $lockedSource->merged_into_customer_id === (int) $lockedTarget->id) {
                    return;
                }

                throw ValidationException::withMessages([
                    'target' => __('This customer was already merged into another customer.'),
                ]);
            }
            if (! $lockedSource->isActive()) {
                throw ValidationException::withMessages([
                    'target' => __('The source customer is no longer active.'),
                ]);
            }
            if (! $lockedTarget->isActive() || $lockedTarget->merged_into_customer_id !== null) {
                throw ValidationException::withMessages([
                    'target' => __('Choose an active destination customer.'),
                ]);
            }

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
            $portalUsers = User::query()
                ->whereIn('customer_id', [$sourceId, $targetId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $sourceUser = $portalUsers->firstWhere('customer_id', $sourceId);
            $targetUser = $portalUsers->firstWhere('customer_id', $targetId);
            if ($sourceUser && $targetUser) {
                $sourceUser->tokens()->delete();
                $sourceUser->forceFill([
                    'status' => 'inactive',
                    'customer_id' => null,
                ])->save();
            } elseif ($sourceUser) {
                $sourceUser->forceFill(['customer_id' => $targetId])->save();
            }

            // Deactivate and annotate the source customer
            $mergeNote = 'Merged into: '.$lockedTarget->name.' (ID '.$targetId.') by user '.$actorId.' on '.now()->toDateTimeString();
            $existingNotes = $lockedSource->notes ? $lockedSource->notes."\n".$mergeNote : $mergeNote;
            $lockedSource->forceFill([
                'is_active' => false,
                'merged_into_customer_id' => $lockedTarget->id,
                'notes' => $existingNotes,
                'updated_by' => $actorId,
            ])->save();
            $this->auditLog->log('customer.merged', $actorId, $lockedSource, [
                'source_customer_id' => (int) $lockedSource->id,
                'destination_customer_id' => (int) $lockedTarget->id,
                'source_portal_user_id' => $sourceUser?->id,
                'destination_portal_user_id' => $targetUser?->id,
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
            'invoices' => DB::table('ar_invoices')->where('customer_id', $id)->count(),
            'payments' => DB::table('payments')->where('customer_id', $id)->count(),
            'orders' => DB::table('orders')->where('customer_id', $id)->count(),
            'subscriptions' => DB::table('meal_subscriptions')->where('customer_id', $id)->count(),
            'sales' => DB::table('sales')->where('customer_id', $id)->count(),
            'pastry_orders' => DB::table('pastry_orders')->where('customer_id', $id)->count(),
        ];
    }
}

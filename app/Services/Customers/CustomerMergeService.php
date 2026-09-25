<?php

namespace App\Services\Customers;

use App\Models\AccountingAuditLog;
use App\Models\Customer;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Payments\PaymentConsistencyDispatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CustomerMergeService
{
    public const LIVE_CUSTOMER_TABLES = [
        'orders',
        'meal_subscriptions',
        'ar_invoices',
        'payments',
        'sales',
        'pastry_orders',
        'meal_plan_requests',
        'quotations',
        'order_sheet_entries',
        'membership_booking_operations',
        'delivery_notes',
    ];

    public function __construct(
        private readonly AccountingAuditLogService $auditLog,
        private readonly CustomerMatchingService $matching,
        private readonly CustomerMatchingDispatchService $matchingDispatches,
        private readonly PaymentConsistencyDispatchService $paymentConsistency,
    ) {}

    /**
     * Move current ownership to the destination while retaining immutable proof rows.
     */
    public function merge(Customer $source, Customer $target, int $actorId): int
    {
        $actor = User::query()->find($actorId);
        if (! $actor || ! $actor->isActive() || ! $actor->isAdmin()) {
            abort(403);
        }
        if ($source->id === $target->id) {
            throw ValidationException::withMessages([
                'target' => __('Source and target customers must be different.'),
            ]);
        }

        $dispatch = null;
        $auditId = DB::transaction(function () use ($source, $target, $actorId, &$dispatch): int {
            $sourceId = (int) $source->id;
            $targetId = (int) $target->id;
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
                if ((int) $lockedSource->merged_into_customer_id === $targetId) {
                    return $this->existingAuditId($sourceId, $targetId);
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

            $portalUsers = User::query()
                ->whereIn('customer_id', [$sourceId, $targetId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $sourceUser = $portalUsers->firstWhere('customer_id', $sourceId);
            $targetUser = $portalUsers->firstWhere('customer_id', $targetId);
            $this->assertPortalLogin($sourceUser);
            $this->assertPortalLogin($targetUser);
            if ($targetUser && ! $targetUser->isActive()) {
                throw ValidationException::withMessages([
                    'target' => __('Activate the destination customer login before merging.'),
                ]);
            }

            $before = [
                'source' => $this->summary($lockedSource),
                'destination' => $this->summary($lockedTarget),
            ];
            $attachedUserOwned = [];
            $movedRecords = [];

            if ($sourceUser) {
                foreach (['orders', 'meal_plan_requests'] as $table) {
                    if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                        $recordIds = DB::table($table)
                            ->where('user_id', $sourceUser->id)
                            ->whereNull('customer_id')
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->pluck('id')
                            ->map(fn ($id): int => (int) $id)
                            ->all();
                        if ($recordIds === []) {
                            continue;
                        }
                        DB::table($table)
                            ->whereIn('id', $recordIds)
                            ->update(['customer_id' => $sourceId]);
                        $attachedUserOwned[$table] = $recordIds;
                    }
                }
            }

            foreach (self::LIVE_CUSTOMER_TABLES as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'customer_id')) {
                    $recordIds = DB::table($table)
                        ->where('customer_id', $sourceId)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->pluck('id')
                        ->map(fn ($id): int => (int) $id)
                        ->all();
                    if ($recordIds === []) {
                        continue;
                    }
                    DB::table($table)->whereIn('id', $recordIds)->update(['customer_id' => $targetId]);
                    $movedRecords[$table] = $recordIds;
                }
            }

            if ($sourceUser && $targetUser) {
                $sourceUser->tokens()->delete();
                if (Schema::hasTable('sessions') && Schema::hasColumn('sessions', 'user_id')) {
                    DB::table('sessions')->where('user_id', $sourceUser->id)->delete();
                }
                $sourceUser->forceFill([
                    'status' => 'inactive',
                    'customer_id' => null,
                    'remember_token' => null,
                ])->save();
            } elseif ($sourceUser) {
                $sourceUser->forceFill(['customer_id' => $targetId])->save();
            }

            $lockedSource->forceFill([
                'is_active' => false,
                'merged_into_customer_id' => $lockedTarget->id,
                'updated_by' => $actorId,
            ])->save();

            $after = [
                'source' => $this->summary($lockedSource->fresh()),
                'destination' => $this->summary($lockedTarget->fresh()),
            ];
            $audit = $this->auditLog->record('customer.merged', $actorId, $lockedSource, [
                'source_customer_id' => $sourceId,
                'destination_customer_id' => $targetId,
                'source_portal_user_id' => $sourceUser?->id,
                'destination_portal_user_id' => $targetUser?->id,
                'surviving_portal_user_id' => $targetUser?->id ?? $sourceUser?->id,
                'disabled_portal_user_id' => $sourceUser && $targetUser ? $sourceUser->id : null,
                'attached_user_owned_records' => $attachedUserOwned,
                'moved_records' => $movedRecords,
                'before' => $before,
                'after' => $after,
            ]);
            if (! $audit) {
                throw new RuntimeException('Customer merge audit storage is unavailable.');
            }

            $survivingUser = $targetUser ?: $sourceUser;
            if ($survivingUser) {
                $fingerprint = $this->matching->recordProfileChange($survivingUser->fresh('customer'));
                if ($fingerprint !== null && (bool) config('customers.matching_enabled', false)) {
                    $dispatch = [(int) $survivingUser->id, $fingerprint];
                }
            }

            return (int) $audit->id;
        }, 3);

        if ($dispatch !== null) {
            $this->matchingDispatches->scanAfterCommit($dispatch[0], $dispatch[1]);
        }
        $this->paymentConsistency->customerAfterCommit(
            (int) $source->id,
            'customer_merge_audit',
            $auditId,
            'source_merged',
        );
        $this->paymentConsistency->customerAfterCommit(
            (int) $target->id,
            'customer_merge_audit',
            $auditId,
            'destination_survived',
        );

        return $auditId;
    }

    /** @return array<string, int|string> */
    public function summary(Customer $source): array
    {
        $id = (int) $source->id;

        return [
            'invoices' => $this->count('ar_invoices', $id),
            'invoice_total' => $this->sum('ar_invoices', 'total_amount', $id),
            'payments' => $this->count('payments', $id),
            'payment_total' => $this->sum('payments', 'amount', $id),
            'orders' => $this->count('orders', $id),
            'subscriptions' => $this->count('meal_subscriptions', $id),
            'sales' => $this->count('sales', $id),
            'pastry_orders' => $this->count('pastry_orders', $id),
            'meal_plan_requests' => $this->count('meal_plan_requests', $id),
            'membership_booking_operations' => $this->count('membership_booking_operations', $id),
        ];
    }

    private function assertPortalLogin(?User $user): void
    {
        if (! $user) {
            return;
        }

        $roles = $user->getRoleNames();
        if ($roles->contains(fn (string $role): bool => $role !== 'customer')) {
            throw ValidationException::withMessages([
                'target' => __('A linked staff account requires manual review before merging customers.'),
            ]);
        }
    }

    private function existingAuditId(int $sourceId, int $targetId): int
    {
        $audit = AccountingAuditLog::query()
            ->where('action', 'customer.merged')
            ->where('subject_id', $sourceId)
            ->where('payload->destination_customer_id', $targetId)
            ->oldest('id')
            ->first();
        if (! $audit) {
            throw new RuntimeException('The prior customer merge audit could not be found.');
        }

        return (int) $audit->id;
    }

    private function count(string $table, int $customerId): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'customer_id')) {
            return 0;
        }

        return DB::table($table)->where('customer_id', $customerId)->count();
    }

    private function sum(string $table, string $column, int $customerId): string
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'customer_id') || ! Schema::hasColumn($table, $column)) {
            return '0.000';
        }

        return number_format((float) DB::table($table)->where('customer_id', $customerId)->sum($column), 3, '.', '');
    }
}

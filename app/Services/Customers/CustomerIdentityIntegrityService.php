<?php

namespace App\Services\Customers;

use App\Models\Customer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CustomerIdentityIntegrityService
{
    /**
     * Columns that retain original identity or control the merge chain rather than move during a merge.
     *
     * @var array<int, string>
     */
    public const RETAINED_REFERENCE_COLUMNS = [
        'customer_match_reviews.candidate_customer_id',
        'customer_match_reviews.customer_id',
        'customer_phone_verification_challenges.customer_id',
        'customers.merged_into_customer_id',
        'membership_promotion_redemptions.original_customer_id',
        'membership_promotion_reservations.original_customer_id',
        'membership_purchase_blocks.original_customer_id',
        'payment_checkout_attempts.customer_id',
        'users.customer_id',
    ];

    /**
     * @return array{ok:bool,checks:array<string, array{count:int,samples:array<int, mixed>}>}
     */
    public function report(int $sampleLimit = 5): array
    {
        $sampleLimit = max(1, min(100, $sampleLimit));
        $checks = [
            'unclassified_references' => $this->unclassifiedReferences($sampleLimit),
            'active_merged_sources' => $this->activeMergedSources($sampleLimit),
            'live_references_on_merged_sources' => $this->liveReferencesOnMergedSources($sampleLimit),
            'duplicate_customer_logins' => $this->duplicateCustomerLogins($sampleLimit),
            'broken_merge_chains' => $this->brokenMergeChains($sampleLimit),
            'source_user_rows_without_customer' => $this->sourceUserRowsWithoutCustomer($sampleLimit),
            'missing_normalized_phone' => $this->missingNormalizedPhones($sampleLimit),
            'untrusted_phone_verification_timestamp' => $this->untrustedVerificationTimestamps($sampleLimit),
        ];

        return [
            'ok' => collect($checks)->every(fn (array $check): bool => $check['count'] === 0),
            'checks' => $checks,
        ];
    }

    /** @return array{count:int,samples:array<int, string>} */
    private function unclassifiedReferences(int $limit): array
    {
        $known = collect(CustomerMergeService::LIVE_CUSTOMER_TABLES)
            ->map(fn (string $table): string => $table.'.customer_id')
            ->merge(self::RETAINED_REFERENCE_COLUMNS)
            ->unique()
            ->flip();

        $namedColumns = DB::table('information_schema.COLUMNS')
            ->select(['TABLE_NAME', 'COLUMN_NAME'])
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->whereIn('COLUMN_NAME', ['customer_id', 'candidate_customer_id', 'original_customer_id', 'merged_into_customer_id'])
            ->get();
        $foreignColumns = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->select(['TABLE_NAME', 'COLUMN_NAME'])
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('REFERENCED_TABLE_NAME', 'customers')
            ->get();
        $unclassified = $namedColumns
            ->merge($foreignColumns)
            ->map(fn (object $column): string => $column->TABLE_NAME.'.'.$column->COLUMN_NAME)
            ->unique()
            ->reject(fn (string $column): bool => $known->has($column))
            ->sort()
            ->values();

        return ['count' => $unclassified->count(), 'samples' => $unclassified->take($limit)->all()];
    }

    /** @return array{count:int,samples:array<int, mixed>} */
    private function activeMergedSources(int $limit): array
    {
        $query = Customer::query()
            ->whereNotNull('merged_into_customer_id')
            ->where('is_active', true);

        return $this->sampledIds($query, $limit);
    }

    /** @return array{count:int,samples:array<int, string>} */
    private function liveReferencesOnMergedSources(int $limit): array
    {
        $count = 0;
        $samples = [];
        foreach (CustomerMergeService::LIVE_CUSTOMER_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'customer_id')) {
                continue;
            }
            $query = DB::table($table.' as records')
                ->join('customers as source_customer', 'source_customer.id', '=', 'records.customer_id')
                ->whereNotNull('source_customer.merged_into_customer_id');
            $count += $query->count();
            if (count($samples) < $limit) {
                $remaining = $limit - count($samples);
                foreach ((clone $query)->orderBy('records.id')->limit($remaining)->pluck('records.id') as $id) {
                    $samples[] = $table.':'.$id;
                }
            }
        }

        return ['count' => $count, 'samples' => $samples];
    }

    /** @return array{count:int,samples:array<int, mixed>} */
    private function duplicateCustomerLogins(int $limit): array
    {
        $duplicates = DB::table('users')
            ->select('customer_id')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) > 1');

        return [
            'count' => DB::query()->fromSub(clone $duplicates, 'duplicate_links')->count(),
            'samples' => DB::query()->fromSub($duplicates, 'duplicate_links')
                ->orderBy('customer_id')
                ->limit($limit)
                ->pluck('customer_id')
                ->all(),
        ];
    }

    /** @return array{count:int,samples:array<int, int>} */
    private function brokenMergeChains(int $limit): array
    {
        $broken = [];
        Customer::query()
            ->select(['id', 'merged_into_customer_id', 'is_active'])
            ->whereNotNull('merged_into_customer_id')
            ->orderBy('id')
            ->chunkById(200, function (Collection $customers) use (&$broken): void {
                foreach ($customers as $customer) {
                    try {
                        $owner = $this->resolveOwner((int) $customer->id);
                        if (! $owner->isActive() || $owner->merged_into_customer_id !== null) {
                            $broken[] = (int) $customer->id;
                        }
                    } catch (RuntimeException) {
                        $broken[] = (int) $customer->id;
                    }
                }
            });

        return ['count' => count($broken), 'samples' => array_slice($broken, 0, $limit)];
    }

    /** @return array{count:int,samples:array<int, string>} */
    private function sourceUserRowsWithoutCustomer(int $limit): array
    {
        $count = 0;
        $samples = [];
        foreach (['orders', 'meal_plan_requests'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id') || ! Schema::hasColumn($table, 'customer_id')) {
                continue;
            }
            $query = DB::table($table.' as records')
                ->join('users as owner_user', 'owner_user.id', '=', 'records.user_id')
                ->whereNull('records.customer_id')
                ->whereNotNull('owner_user.customer_id');
            $count += $query->count();
            if (count($samples) < $limit) {
                foreach ((clone $query)->orderBy('records.id')->limit($limit - count($samples))->pluck('records.id') as $id) {
                    $samples[] = $table.':'.$id;
                }
            }
        }

        return ['count' => $count, 'samples' => $samples];
    }

    /** @return array{count:int,samples:array<int, string>} */
    private function missingNormalizedPhones(int $limit): array
    {
        $userQuery = DB::table('users')
            ->whereNotNull('portal_phone')
            ->where('portal_phone', '<>', '')
            ->whereNull('portal_phone_e164');
        $customerQuery = DB::table('customers')
            ->whereNotNull('phone')
            ->where('phone', '<>', '')
            ->whereNull('phone_e164');
        $samples = (clone $userQuery)->orderBy('id')->limit($limit)->pluck('id')
            ->map(fn ($id): string => 'users:'.$id)
            ->all();
        if (count($samples) < $limit) {
            foreach ((clone $customerQuery)->orderBy('id')->limit($limit - count($samples))->pluck('id') as $id) {
                $samples[] = 'customers:'.$id;
            }
        }

        return ['count' => $userQuery->count() + $customerQuery->count(), 'samples' => $samples];
    }

    /** @return array{count:int,samples:array<int, mixed>} */
    private function untrustedVerificationTimestamps(int $limit): array
    {
        $recognizedPurposes = [
            CustomerPhoneVerificationService::PURPOSE_SIGNUP,
            CustomerPhoneVerificationService::PURPOSE_PHONE_CHANGE,
            CustomerPhoneVerificationService::PURPOSE_CURRENT_PHONE,
        ];
        $query = DB::table('users as portal_user')
            ->leftJoin('customers as linked_customer', 'linked_customer.id', '=', 'portal_user.customer_id')
            ->where(function ($timestamp): void {
                $timestamp->whereNotNull('portal_user.portal_phone_verified_at')
                    ->orWhereNotNull('linked_customer.phone_verified_at');
            })
            ->whereNotExists(function ($proof) use ($recognizedPurposes): void {
                $proof->selectRaw('1')
                    ->from('customer_phone_verification_challenges as challenge')
                    ->whereColumn('challenge.user_id', 'portal_user.id')
                    ->whereNull('challenge.cancelled_at')
                    ->whereNotNull('challenge.verified_at')
                    ->whereIn('challenge.purpose', $recognizedPurposes)
                    ->whereRaw('BINARY challenge.phone_e164 = BINARY COALESCE(portal_user.portal_phone_e164, linked_customer.phone_e164)');
            });

        return $this->sampledIds($query, $limit, 'portal_user.id');
    }

    private function resolveOwner(int $customerId): Customer
    {
        $seen = [];
        while (true) {
            if (isset($seen[$customerId])) {
                throw new RuntimeException('Customer merge cycle detected.');
            }
            $seen[$customerId] = true;
            $customer = Customer::query()->select(['id', 'merged_into_customer_id', 'is_active'])->find($customerId);
            if (! $customer) {
                throw new RuntimeException('Customer merge destination is missing.');
            }
            if ($customer->merged_into_customer_id === null) {
                return $customer;
            }
            $customerId = (int) $customer->merged_into_customer_id;
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder  $query
     * @return array{count:int,samples:array<int, mixed>}
     */
    private function sampledIds($query, int $limit, string $column = 'id'): array
    {
        return [
            'count' => (clone $query)->count(),
            'samples' => (clone $query)->orderBy($column)->limit($limit)->pluck($column)->all(),
        ];
    }
}

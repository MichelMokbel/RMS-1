<?php

namespace App\Services\Customers;

use App\Models\Customer;
use RuntimeException;

class CustomerOwnershipService
{
    /**
     * Return the active customer that owns new financial work for a historical customer reference.
     */
    public function canonicalCustomerId(int $customerId): int
    {
        $chain = $this->customerChain($customerId);

        return $chain[array_key_last($chain)];
    }

    /**
     * Lock and return the current customer owner while an outer financial transaction is active.
     */
    public function lockCanonicalCustomer(int $customerId): Customer
    {
        $currentId = $customerId;
        $seen = [];

        while (true) {
            if (isset($seen[$currentId])) {
                throw new RuntimeException('Customer merge ownership contains a cycle.');
            }
            $seen[$currentId] = true;

            $customer = Customer::query()->lockForUpdate()->findOrFail($currentId);
            if ($customer->merged_into_customer_id === null) {
                return $customer;
            }
            $currentId = (int) $customer->merged_into_customer_id;
        }
    }

    /**
     * Return the canonical customer and every source customer that now belongs to it.
     *
     * @return array<int, int>
     */
    public function historicalCustomerIds(int $customerId): array
    {
        $ids = [$this->canonicalCustomerId($customerId)];
        $known = array_fill_keys($ids, true);

        do {
            $sourceIds = Customer::query()
                ->whereIn('merged_into_customer_id', array_keys($known))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $newIds = array_values(array_filter($sourceIds, fn (int $id): bool => ! isset($known[$id])));
            foreach ($newIds as $id) {
                $known[$id] = true;
                $ids[] = $id;
            }
        } while ($newIds !== []);

        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @return array<int, int>
     */
    private function customerChain(int $customerId): array
    {
        $currentId = $customerId;
        $chain = [];
        $seen = [];

        while (true) {
            if (isset($seen[$currentId])) {
                throw new RuntimeException('Customer merge ownership contains a cycle.');
            }
            $seen[$currentId] = true;
            $chain[] = $currentId;

            $customer = Customer::query()->select(['id', 'merged_into_customer_id'])->findOrFail($currentId);
            if ($customer->merged_into_customer_id === null) {
                return $chain;
            }
            $currentId = (int) $customer->merged_into_customer_id;
        }
    }
}

<?php

namespace App\Services\Customers;

use App\Models\Customer;

class CustomerUpsertMatcher
{
    public function findMatch(array $row): ?Customer
    {
        if (! empty($row['customer_code'])) {
            $customer = Customer::where('customer_code', $row['customer_code'])->first();
            if ($customer) {
                return $customer;
            }
        }

        if (! empty($row['email'])) {
            $customer = Customer::where('email', $row['email'])->first();
            if ($customer) {
                return $customer;
            }
        }

        if (! empty($row['phone'])) {
            $customer = Customer::where('phone', $row['phone'])->first();
            if ($customer) {
                return $customer;
            }
        }

        if (! empty($row['name']) && ! empty($row['customer_type'])) {
            return Customer::where('name', $row['name'])
                ->where('customer_type', $row['customer_type'])
                ->first();
        }

        return null;
    }
}

<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Services\Sequences\DocumentSequenceService;
use Illuminate\Support\Facades\Schema;

class CustomerCodeService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
    ) {
    }

    public function nextCode(): string
    {
        $sequence = $this->sequences->nextWithSeed(
            'customer_code',
            1,
            '0000',
            $this->maxNumericCode()
        );

        return $this->format($sequence);
    }

    public function previewCode(): string
    {
        return $this->format($this->maxNumericCode() + 1);
    }

    public function format(int $number): string
    {
        return 'CUST-'.str_pad((string) max(1, $number), 4, '0', STR_PAD_LEFT);
    }

    public function maxNumericCode(): int
    {
        if (! Schema::hasTable('customers')) {
            return 0;
        }

        return (int) (Customer::query()
            ->whereNotNull('customer_code')
            ->whereRaw("customer_code REGEXP '^CUST-[0-9]+$'")
            ->selectRaw('MAX(CAST(SUBSTRING(customer_code, 6) AS UNSIGNED)) as max_code')
            ->value('max_code') ?? 0);
    }
}

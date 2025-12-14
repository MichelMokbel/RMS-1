<?php

namespace App\Services\Orders;

use Illuminate\Support\Facades\DB;

class OrderNumberService
{
    public function generate(): string
    {
        $year = now()->format('Y');
        $prefix = 'ORD'.$year.'-';

        do {
            $number = $prefix.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (DB::table('orders')->where('order_number', $number)->exists());

        return $number;
    }
}


<?php

namespace App\Services\Orders;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderNumberService
{
    public function generate(): string
    {
        if (! Schema::hasTable('order_number_sequences')) {
            return $this->generateLegacyRandom();
        }

        $year = now()->format('Y');
        $prefix = 'ORD'.$year.'-';

        $seq = $this->nextSequenceNumber($year);
        $number = $prefix.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);

        return $number;
    }

    private function nextSequenceNumber(string $year): int
    {
        return (int) DB::transaction(function () use ($year) {
            $row = DB::table('order_number_sequences')
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                try {
                    DB::table('order_number_sequences')->insert([
                        'year' => $year,
                        'next_number' => 2,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    return 1;
                } catch (QueryException $e) {
                    // Another transaction inserted concurrently; retry read-for-update.
                    $row = DB::table('order_number_sequences')
                        ->where('year', $year)
                        ->lockForUpdate()
                        ->first();
                }
            }

            $current = (int) ($row->next_number ?? 1);
            DB::table('order_number_sequences')
                ->where('year', $year)
                ->update(['next_number' => $current + 1, 'updated_at' => now()]);

            return $current;
        });
    }

    private function generateLegacyRandom(): string
    {
        $year = now()->format('Y');
        $prefix = 'ORD'.$year.'-';

        do {
            $number = $prefix.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (DB::table('orders')->where('order_number', $number)->exists());

        return $number;
    }
}


<?php

namespace App\Services\POS;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PosSequenceService
{
    /**
     * @return array{reserved_start:int,reserved_end:int}
     */
    public function reserve(int $terminalId, string $businessDate, int $count): array
    {
        $terminalId = (int) $terminalId;
        $count = max(1, (int) $count);

        return DB::transaction(function () use ($terminalId, $businessDate, $count) {
            $row = DB::table('pos_document_sequences')
                ->where('terminal_id', $terminalId)
                ->where('business_date', $businessDate)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                try {
                    DB::table('pos_document_sequences')->insert([
                        'terminal_id' => $terminalId,
                        'business_date' => $businessDate,
                        'last_number' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (QueryException $e) {
                    // Another transaction inserted concurrently; proceed to locked read.
                }

                $row = DB::table('pos_document_sequences')
                    ->where('terminal_id', $terminalId)
                    ->where('business_date', $businessDate)
                    ->lockForUpdate()
                    ->first();
            }

            $last = (int) ($row->last_number ?? 0);
            $reservedStart = $last + 1;
            $reservedEnd = $last + $count;

            DB::table('pos_document_sequences')
                ->where('terminal_id', $terminalId)
                ->where('business_date', $businessDate)
                ->update(['last_number' => $reservedEnd, 'updated_at' => now()]);

            return [
                'reserved_start' => $reservedStart,
                'reserved_end' => $reservedEnd,
            ];
        });
    }
}


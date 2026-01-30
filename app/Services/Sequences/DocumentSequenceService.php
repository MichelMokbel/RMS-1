<?php

namespace App\Services\Sequences;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DocumentSequenceService
{
    /**
     * Returns the next sequence number for (branch_id, type, year) using row locks.
     */
    public function next(string $type, int $branchId, ?string $year = null): int
    {
        $branchId = (int) $branchId;
        if ($branchId <= 0) {
            $branchId = 1;
        }

        $year = $year ?: now()->format('Y');

        if (! Schema::hasTable('document_sequences')) {
            // Fallback: not concurrency-safe, but avoids crashes during partial installs.
            return random_int(1, 999999);
        }

        return (int) DB::transaction(function () use ($type, $branchId, $year) {
            $row = DB::table('document_sequences')
                ->where('branch_id', $branchId)
                ->where('type', $type)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                try {
                    DB::table('document_sequences')->insert([
                        'branch_id' => $branchId,
                        'type' => $type,
                        'year' => $year,
                        'next_number' => 2,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    return 1;
                } catch (QueryException $e) {
                    // Another transaction inserted concurrently; retry read-for-update.
                    $row = DB::table('document_sequences')
                        ->where('branch_id', $branchId)
                        ->where('type', $type)
                        ->where('year', $year)
                        ->lockForUpdate()
                        ->first();
                }
            }

            $current = (int) ($row->next_number ?? 1);
            DB::table('document_sequences')
                ->where('branch_id', $branchId)
                ->where('type', $type)
                ->where('year', $year)
                ->update(['next_number' => $current + 1, 'updated_at' => now()]);

            return $current;
        });
    }
}


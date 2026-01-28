<?php

namespace App\Services\Ledger;

use App\Models\GlBatch;
use App\Models\GlBatchLine;
use App\Models\SubledgerLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class GlSummaryService
{
    public function generateForPeriod(Carbon $periodStart, Carbon $periodEnd, int $userId): GlBatch
    {
        if (! $this->canSummarize()) {
            throw ValidationException::withMessages(['ledger' => __('Ledger tables are not available.')]);
        }

        $batch = GlBatch::firstOrCreate(
            ['period_start' => $periodStart->toDateString(), 'period_end' => $periodEnd->toDateString()],
            ['status' => 'open', 'created_by' => $userId]
        );

        if ($batch->status !== 'open') {
            throw ValidationException::withMessages(['ledger' => __('GL batch is closed.')]);
        }

        return DB::transaction(function () use ($batch, $periodStart, $periodEnd, $userId) {
            $batch->lines()->delete();

            $rows = SubledgerLine::query()
                ->join('subledger_entries', 'subledger_entries.id', '=', 'subledger_lines.entry_id')
                ->where('subledger_entries.status', 'posted')
                ->whereBetween('subledger_entries.entry_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->select('subledger_lines.account_id')
                ->selectRaw('SUM(subledger_lines.debit) as debit_total')
                ->selectRaw('SUM(subledger_lines.credit) as credit_total')
                ->groupBy('subledger_lines.account_id')
                ->get();

            foreach ($rows as $row) {
                GlBatchLine::create([
                    'batch_id' => $batch->id,
                    'account_id' => $row->account_id,
                    'debit_total' => round((float) $row->debit_total, 4),
                    'credit_total' => round((float) $row->credit_total, 4),
                ]);
            }

            $batch->generated_at = now();
            $batch->created_by = $userId;
            $batch->save();

            return $batch->fresh('lines');
        });
    }

    private function canSummarize(): bool
    {
        return Schema::hasTable('gl_batches')
            && Schema::hasTable('gl_batch_lines')
            && Schema::hasTable('subledger_entries')
            && Schema::hasTable('subledger_lines');
    }
}

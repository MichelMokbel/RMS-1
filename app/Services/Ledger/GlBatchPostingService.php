<?php

namespace App\Services\Ledger;

use App\Models\GlBatch;
use App\Services\Finance\FinanceSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GlBatchPostingService
{
    public function __construct(protected FinanceSettingsService $financeSettings)
    {
    }

    public function post(GlBatch $batch, int $userId, bool $closePeriod = true): GlBatch
    {
        return DB::transaction(function () use ($batch, $userId, $closePeriod) {
            $batch = GlBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($batch->status !== 'open') {
                throw ValidationException::withMessages(['ledger' => __('GL batch is not open.')]);
            }

            if (! $batch->generated_at) {
                throw ValidationException::withMessages(['ledger' => __('Generate the GL batch before posting.')]);
            }

            $lines = $batch->lines()->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['ledger' => __('GL batch has no lines.')]);
            }

            $debits = round((float) $lines->sum('debit_total'), 4);
            $credits = round((float) $lines->sum('credit_total'), 4);
            if (abs($debits - $credits) > 0.0001) {
                throw ValidationException::withMessages(['ledger' => __('GL batch is unbalanced.')]);
            }

            $batch->status = 'posted';
            $batch->posted_at = now();
            $batch->posted_by = $userId;
            $batch->save();

            if ($closePeriod) {
                $target = $batch->period_end ? Carbon::parse($batch->period_end)->toDateString() : null;
                if ($target) {
                    $current = $this->financeSettings->getLockDate();
                    if ($current && Carbon::parse($target)->lessThan(Carbon::parse($current))) {
                        throw ValidationException::withMessages(['lock_date' => __('Lock date cannot move backwards.')]);
                    }
                    $this->financeSettings->setLockDate($target, $userId);
                }
            }

            return $batch->fresh('lines');
        });
    }
}

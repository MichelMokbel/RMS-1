<?php

namespace App\Services\Finance;

use App\Models\FinanceSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class FinanceSettingsService
{
    public function getLockDate(): ?string
    {
        if (! Schema::hasTable('finance_settings')) {
            return null;
        }

        $row = FinanceSetting::query()->find(1);
        return $row?->lock_date?->toDateString();
    }

    public function setLockDate(?string $lockDate, ?int $userId = null): ?string
    {
        if (! Schema::hasTable('finance_settings')) {
            throw ValidationException::withMessages(['finance' => __('Finance settings table is missing.')]);
        }

        $normalized = null;
        if ($lockDate !== null && trim($lockDate) !== '') {
            try {
                $normalized = Carbon::parse($lockDate)->toDateString();
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['lock_date' => __('Invalid lock date.')]);
            }
        }

        $row = FinanceSetting::query()->firstOrCreate(['id' => 1], []);
        $row->lock_date = $normalized;
        $row->updated_by = $userId;
        $row->save();

        return $normalized;
    }
}

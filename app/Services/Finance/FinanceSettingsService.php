<?php

namespace App\Services\Finance;

use App\Models\FinanceSetting;
use Illuminate\Support\Arr;
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

    public function setLockDate(?string $lockDate, ?int $userId = null, bool $allowBackward = false): ?string
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
        $current = $row->lock_date?->toDateString();
        if (! $allowBackward && $normalized !== null && $current !== null) {
            if (Carbon::parse($normalized)->lt(Carbon::parse($current))) {
                throw ValidationException::withMessages(['lock_date' => __('Lock date cannot move backwards.')]);
            }
        }
        $row->lock_date = $normalized;
        $row->updated_by = $userId;
        $row->save();

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        if (! Schema::hasTable('finance_settings')) {
            return [
                'lock_date' => null,
                'default_company_id' => null,
                'default_bank_account_id' => null,
                'po_quantity_tolerance_percent' => 0.0,
                'po_price_tolerance_percent' => 0.0,
                'purchase_price_variance_account_id' => null,
            ];
        }

        $row = FinanceSetting::query()->firstOrCreate(['id' => 1], []);

        return [
            'lock_date' => $row->lock_date?->toDateString(),
            'default_company_id' => $row->default_company_id,
            'default_bank_account_id' => $row->default_bank_account_id,
            'po_quantity_tolerance_percent' => round((float) ($row->po_quantity_tolerance_percent ?? 0), 3),
            'po_price_tolerance_percent' => round((float) ($row->po_price_tolerance_percent ?? 0), 3),
            'purchase_price_variance_account_id' => $row->purchase_price_variance_account_id,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveSettings(array $data, ?int $userId = null): array
    {
        if (! Schema::hasTable('finance_settings')) {
            throw ValidationException::withMessages(['finance' => __('Finance settings table is missing.')]);
        }

        $settings = FinanceSetting::query()->firstOrCreate(['id' => 1], []);

        $settings->fill([
            'default_company_id' => Arr::get($data, 'default_company_id'),
            'default_bank_account_id' => Arr::get($data, 'default_bank_account_id'),
            'po_quantity_tolerance_percent' => round((float) Arr::get($data, 'po_quantity_tolerance_percent', 0), 3),
            'po_price_tolerance_percent' => round((float) Arr::get($data, 'po_price_tolerance_percent', 0), 3),
            'purchase_price_variance_account_id' => Arr::get($data, 'purchase_price_variance_account_id'),
            'updated_by' => $userId,
        ])->save();

        if (array_key_exists('lock_date', $data)) {
            $this->setLockDate(Arr::get($data, 'lock_date'), $userId, false);
        }

        return $this->getSettings();
    }
}

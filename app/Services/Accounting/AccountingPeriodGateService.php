<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\PeriodLock;
use App\Services\Finance\FinanceSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AccountingPeriodGateService
{
    public function __construct(
        protected FinanceSettingsService $financeSettings
    ) {}

    public function assertDateOpen(
        string $date,
        ?int $companyId = null,
        ?int $periodId = null,
        string $module = 'all',
        string $errorKey = 'period'
    ): void {
        $normalizedDate = Carbon::parse($date)->toDateString();

        $lockDate = $this->financeSettings->getLockDate();
        if ($lockDate && Carbon::parse($normalizedDate)->startOfDay()->lessThanOrEqualTo(Carbon::parse($lockDate)->startOfDay())) {
            throw ValidationException::withMessages([
                $errorKey => __('Posting is locked for periods on or before :date.', ['date' => $lockDate]),
            ]);
        }

        if (! Schema::hasTable('accounting_periods')) {
            if (app()->isProduction()) {
                throw ValidationException::withMessages([
                    $errorKey => __('Accounting periods are not configured. Contact your system administrator.'),
                ]);
            }

            return;
        }

        $period = $periodId
            ? AccountingPeriod::query()->find($periodId)
            : AccountingPeriod::query()
                ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
                ->whereDate('start_date', '<=', $normalizedDate)
                ->whereDate('end_date', '>=', $normalizedDate)
                ->first();

        if (! $period) {
            throw ValidationException::withMessages([
                $errorKey => __('No accounting period exists for :date.', ['date' => $normalizedDate]),
            ]);
        }

        if ($this->isLocked($period, $module) || $period->status === 'closed') {
            throw ValidationException::withMessages([
                $errorKey => __('The accounting period :period is closed.', ['period' => $period->name]),
            ]);
        }
    }

    public function isLocked(AccountingPeriod $period, string $module = 'all'): bool
    {
        if (! Schema::hasTable('period_locks')) {
            return false;
        }

        $lock = PeriodLock::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->whereIn('module', ['all', $module])
            ->latest('id')
            ->first();

        if (! $lock) {
            return false;
        }

        return in_array($lock->lock_type, ['soft', 'hard', 'close', 'closed'], true);
    }
}

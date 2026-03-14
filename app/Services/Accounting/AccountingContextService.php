<?php

namespace App\Services\Accounting;

use App\Models\AccountingCompany;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\Branch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class AccountingContextService
{
    public function defaultCompanyId(): ?int
    {
        if (! Schema::hasTable('accounting_companies')) {
            return null;
        }

        $companyId = AccountingCompany::query()->where('is_default', true)->value('id');

        return $companyId ? (int) $companyId : null;
    }

    public function resolveCompanyId(?int $branchId = null, ?int $explicitCompanyId = null): ?int
    {
        if ($explicitCompanyId && $explicitCompanyId > 0) {
            return $explicitCompanyId;
        }

        if ($branchId && $branchId > 0 && Schema::hasTable('branches') && Schema::hasColumn('branches', 'company_id')) {
            $companyId = Branch::query()->whereKey($branchId)->value('company_id');
            if ($companyId) {
                return (int) $companyId;
            }
        }

        return $this->defaultCompanyId();
    }

    public function resolvePeriodId(?string $date = null, ?int $companyId = null): ?int
    {
        $period = $this->resolvePeriod($date, $companyId);

        return $period ? (int) $period->id : null;
    }

    public function resolvePeriod(?string $date = null, ?int $companyId = null): ?AccountingPeriod
    {
        if (! Schema::hasTable('accounting_periods')) {
            return null;
        }

        $companyId = $companyId ?: $this->defaultCompanyId();
        if (! $companyId) {
            return null;
        }

        $dateValue = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();

        return AccountingPeriod::query()
            ->where('company_id', $companyId)
            ->whereDate('start_date', '<=', $dateValue)
            ->whereDate('end_date', '>=', $dateValue)
            ->first();
    }

    public function defaultBankAccountId(?int $companyId = null): ?int
    {
        if (! Schema::hasTable('bank_accounts')) {
            return null;
        }

        $companyId = $companyId ?: $this->defaultCompanyId();
        if (! $companyId) {
            return null;
        }

        $bankId = BankAccount::query()
            ->where('company_id', $companyId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereNotNull('ledger_account_id')
            ->value('id');

        return $bankId ? (int) $bankId : null;
    }
}

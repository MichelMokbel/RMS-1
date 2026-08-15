<?php

namespace App\Console\Commands;

use App\Models\AccountingCompany;
use App\Services\HR\HrAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class HrRefreshAlerts extends Command
{
    protected $signature = 'hr:refresh-alerts {--company= : Refresh one accounting company ID}';

    protected $description = 'Refresh dashboard-only HR document compliance alerts';

    public function handle(HrAlertService $alerts): int
    {
        if (! Schema::hasTable('hr_alerts')) {
            $this->warn('HR tables are not installed; alert refresh skipped.');

            return self::SUCCESS;
        }

        $companyId = $this->option('company');
        $companies = AccountingCompany::query()->when($companyId, fn ($query) => $query->whereKey((int) $companyId))->pluck('id');
        foreach ($companies as $id) {
            $result = $alerts->refreshCompany((int) $id);
            $this->line("Company {$id}: {$result['opened']} opened, {$result['resolved']} resolved.");
        }

        return self::SUCCESS;
    }
}

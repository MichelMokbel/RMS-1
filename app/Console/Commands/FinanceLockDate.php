<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceSettingsService;
use Illuminate\Console\Command;

class FinanceLockDate extends Command
{
    protected $signature = 'finance:lock-date {date? : YYYY-MM-DD} {--clear : Clear the lock date}';

    protected $description = 'Set or clear the finance lock date (period close)';

    public function handle(FinanceSettingsService $service): int
    {
        if ((bool) $this->option('clear')) {
            $service->setLockDate(null, null);
            $this->info('Finance lock date cleared.');
            return Command::SUCCESS;
        }

        $date = (string) ($this->argument('date') ?? '');
        if (trim($date) === '') {
            $current = $service->getLockDate();
            $this->line('Current lock date: '.($current ?: '—'));
            return Command::SUCCESS;
        }

        $normalized = $service->setLockDate($date, null);
        $this->info('Finance lock date set to: '.$normalized);

        return Command::SUCCESS;
    }
}

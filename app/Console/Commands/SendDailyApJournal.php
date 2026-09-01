<?php

namespace App\Console\Commands;

use App\Services\Reports\DailyApJournalService;
use Illuminate\Console\Command;

class SendDailyApJournal extends Command
{
    protected $signature = 'reports:send-ap-journal {--date= : Accounting calendar date, defaults to today} {--retry-failed : Retry a failed delivery after checking the mail provider for duplicates}';

    protected $description = 'Generate and email the daily AP journal once per company and calendar date';

    public function handle(DailyApJournalService $service): int
    {
        $result = $service->send((string) ($this->option('date') ?: now()->toDateString()), (bool) $this->option('retry-failed'));
        $this->info(__('AP journal delivery: :result', ['result' => $result]));

        return self::SUCCESS;
    }
}

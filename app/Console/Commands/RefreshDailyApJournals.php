<?php

namespace App\Console\Commands;

use App\Services\Reports\DailyApJournalService;
use Illuminate\Console\Command;

class RefreshDailyApJournals extends Command
{
    protected $signature = 'reports:refresh-ap-journals';

    protected $description = 'Regenerate saved daily AP journals when entries are added for their accounting date';

    public function handle(DailyApJournalService $service): int
    {
        $this->info(__('Refreshed :count daily AP reports.', ['count' => $service->refreshChanged()]));

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Payments\SkipCashRecoveryService;
use Illuminate\Console\Command;

class PurgeSkipCashProviderBodies extends Command
{
    protected $signature = 'payments:purge-skipcash-provider-bodies {--days= : Retain raw bodies for at least this many days}';

    protected $description = 'Remove only expired encrypted raw SkipCash event bodies.';

    public function handle(SkipCashRecoveryService $recovery): int
    {
        $days = $this->option('days');
        $purged = $recovery->purgeRawEventBodies($days === null ? null : (int) $days);
        $this->info("Purged {$purged} raw SkipCash provider body record(s).");

        return self::SUCCESS;
    }
}

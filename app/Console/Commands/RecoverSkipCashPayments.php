<?php

namespace App\Console\Commands;

use App\Services\Payments\SkipCashRecoveryService;
use Illuminate\Console\Command;

class RecoverSkipCashPayments extends Command
{
    protected $signature = 'payments:recover-skipcash {--limit=100 : Maximum records to examine in each recovery pass}';

    protected $description = 'Recover durable SkipCash checkout dispatches, confirmations, and provider events.';

    public function handle(SkipCashRecoveryService $recovery): int
    {
        $result = $recovery->recover((int) $this->option('limit'));
        $this->info('SkipCash recovery complete: '.json_encode($result, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

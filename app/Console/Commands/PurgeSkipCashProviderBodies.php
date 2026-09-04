<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentOperationsHealthService;
use App\Services\Payments\SkipCashRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class PurgeSkipCashProviderBodies extends Command
{
    protected $signature = 'payments:purge-skipcash-provider-bodies {--days= : Retain raw bodies for at least this many days}';

    protected $description = 'Remove only expired encrypted raw SkipCash event bodies.';

    public function handle(
        SkipCashRecoveryService $recovery,
        PaymentOperationsHealthService $health,
    ): int {
        try {
            $days = $this->option('days');
            $purged = $recovery->purgeRawEventBodies($days === null ? null : (int) $days);
            $health->recordSuccess('purge', ['purged' => $purged]);
            $this->info("Purged {$purged} raw SkipCash provider body record(s).");
        } catch (Throwable $exception) {
            $health->recordFailure('purge', 'PURGE_COMMAND_FAILED');
            Log::error('skipcash_purge_command_failed', [
                'exception_class' => $exception::class,
            ]);
            $this->error('SkipCash provider evidence purge failed. Check the application log.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

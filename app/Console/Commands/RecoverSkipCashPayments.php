<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentOperationsHealthService;
use App\Services\Payments\SkipCashRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecoverSkipCashPayments extends Command
{
    protected $signature = 'payments:recover-skipcash {--limit=100 : Maximum records to examine in each recovery pass}';

    protected $description = 'Recover durable SkipCash checkout dispatches, confirmations, and provider events.';

    public function handle(
        SkipCashRecoveryService $recovery,
        PaymentOperationsHealthService $health,
    ): int {
        try {
            $result = $recovery->recover((int) $this->option('limit'));
            $health->recordSuccess('recovery', $result);
            $this->info('SkipCash recovery complete: '.json_encode($result, JSON_UNESCAPED_SLASHES));
        } catch (Throwable $exception) {
            $health->recordFailure('recovery', 'RECOVERY_COMMAND_FAILED');
            Log::error('skipcash_recovery_command_failed', [
                'exception_class' => $exception::class,
            ]);
            $this->error('SkipCash recovery failed. Check the application log.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

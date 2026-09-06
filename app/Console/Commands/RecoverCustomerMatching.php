<?php

namespace App\Console\Commands;

use App\Services\Customers\CustomerMatchingRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecoverCustomerMatching extends Command
{
    protected $signature = 'customers:recover-matching {--limit=100 : Maximum missing account generations to dispatch}';

    protected $description = 'Dispatch missing customer duplicate scans without delaying signup or checkout.';

    public function handle(CustomerMatchingRecoveryService $recovery): int
    {
        try {
            $result = $recovery->dispatchMissing((int) $this->option('limit'));
            $this->info('Customer matching recovery complete: '.json_encode($result, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('customer_matching_recovery_failed', [
                'exception_class' => $exception::class,
            ]);
            $this->error('Customer matching recovery failed. Check the application log.');

            return self::FAILURE;
        }
    }
}

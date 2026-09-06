<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentConsistencySweepService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckPaymentConsistency extends Command
{
    protected $signature = 'payments:check-consistency
        {--mode=catchup : Sweep mode: catchup or full}
        {--company= : Optional accounting company ID}';

    protected $description = 'Queue nonblocking payment consistency checks.';

    public function handle(PaymentConsistencySweepService $sweeps): int
    {
        try {
            $mode = strtolower(trim((string) $this->option('mode')));
            if (! in_array($mode, ['catchup', 'full'], true)) {
                $this->error('Mode must be catchup or full.');

                return self::INVALID;
            }
            $company = trim((string) $this->option('company'));
            if ($company !== '' && (! ctype_digit($company) || (int) $company <= 0)) {
                $this->error('Company must be a positive integer.');

                return self::INVALID;
            }

            $result = $sweeps->dispatch($mode, $company === '' ? null : (int) $company);
            $this->info('Payment consistency dispatch complete: '.json_encode($result, JSON_UNESCAPED_SLASHES));

            return $result['dispatch_failed'] === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            Log::error('payment_consistency_command_failed', [
                'exception_class' => $exception::class,
            ]);
            $this->error('Payment consistency dispatch failed. Check the application log.');

            return self::FAILURE;
        }
    }
}

<?php

namespace App\Services\Customers;

use App\Jobs\RankCustomerMatchCandidates;
use App\Jobs\ScanCustomerMatchCandidates;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CustomerMatchingDispatchService
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
    ) {}

    public function scanAfterCommit(int $userId, string $fingerprint): void
    {
        DB::afterCommit(fn (): bool => $this->dispatchScan($userId, $fingerprint));
    }

    public function dispatchScan(int $userId, string $fingerprint): bool
    {
        return $this->dispatch(new ScanCustomerMatchCandidates($userId, $fingerprint), 'scan', $userId);
    }

    public function dispatchRank(int $userId, string $fingerprint): bool
    {
        return $this->dispatch(new RankCustomerMatchCandidates($userId, $fingerprint), 'rank', $userId);
    }

    private function dispatch(object $job, string $kind, int $userId): bool
    {
        try {
            $this->dispatcher->dispatch($job);

            return true;
        } catch (Throwable $exception) {
            Log::warning('customer_matching_dispatch_failed', [
                'kind' => $kind,
                'user_id' => $userId,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}

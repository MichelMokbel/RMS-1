<?php

namespace App\Jobs;

use App\Services\Customers\CustomerMatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScanCustomerMatchCandidates implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $userId,
        public readonly string $profileFingerprint,
    ) {}

    public function handle(CustomerMatchingService $matching): void
    {
        $matching->scan($this->userId, $this->profileFingerprint);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function uniqueId(): string
    {
        return 'customer-match-scan:'.$this->userId.':'.$this->profileFingerprint;
    }
}

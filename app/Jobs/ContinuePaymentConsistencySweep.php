<?php

namespace App\Jobs;

use App\Services\Payments\PaymentConsistencySweepService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ContinuePaymentConsistencySweep implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $parentRunId) {}

    public function handle(PaymentConsistencySweepService $sweeps): void
    {
        $sweeps->continue($this->parentRunId);
    }

    public function uniqueId(): string
    {
        return (string) $this->parentRunId;
    }
}

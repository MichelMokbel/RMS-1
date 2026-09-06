<?php

namespace App\Jobs;

use App\Services\Payments\PaymentConsistencyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunPaymentConsistency implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $ruleCode,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly string $kind,
        public readonly string $triggerKey,
        public readonly ?int $requestedBy = null,
        public readonly ?int $parentRunId = null,
    ) {}

    public function handle(PaymentConsistencyService $consistency): void
    {
        if (! (bool) config('payment_consistency.enabled', false)) {
            return;
        }

        $consistency->check(
            $this->ruleCode,
            $this->subjectType,
            $this->subjectId,
            $this->kind,
            $this->requestedBy,
            $this->triggerKey,
            $this->parentRunId,
        );
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function uniqueId(): string
    {
        return implode(':', [$this->ruleCode, $this->subjectType, $this->subjectId, $this->triggerKey]);
    }
}

<?php

namespace App\Jobs;

use App\Models\MembershipPromotion;
use App\Services\Payments\PaymentConsistencyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunPromotionPaymentConsistency implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $promotionId,
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

        $promotion = MembershipPromotion::query()->find($this->promotionId);
        if (! $promotion) {
            return;
        }

        $consistency->checkPromotion(
            $promotion,
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
        return 'promotion:'.$this->promotionId.':'.$this->triggerKey;
    }
}

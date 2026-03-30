<?php

namespace App\Services\Subscriptions;

use App\Models\MealSubscription;
use App\Services\Sequences\DocumentSequenceService;

class MealSubscriptionCodeService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
    ) {
    }

    public function generate(): string
    {
        $year = now()->format('Y');
        $branchId = 1;

        do {
            $sequence = $this->sequences->next('meal_subscription', $branchId, $year);
            $code = sprintf('SUB-%s-%06d', $year, $sequence);
        } while (MealSubscription::where('subscription_code', $code)->exists());

        return $code;
    }
}

<?php

namespace App\Services\Subscriptions;

use App\Models\MealSubscription;
use Illuminate\Support\Str;

class MealSubscriptionCodeService
{
    public function generate(): string
    {
        $year = now()->format('Y');
        $prefix = "SUB-{$year}-";

        do {
            $code = $prefix . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (MealSubscription::where('subscription_code', $code)->exists());

        return $code;
    }
}


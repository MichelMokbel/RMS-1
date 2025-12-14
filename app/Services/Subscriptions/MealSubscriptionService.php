<?php

namespace App\Services\Subscriptions;

use App\Models\MealSubscription;
use App\Models\MealSubscriptionDay;
use App\Models\MealSubscriptionPause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MealSubscriptionService
{
    public function __construct(protected MealSubscriptionCodeService $codeService)
    {
    }

    public function save(array $payload, ?MealSubscription $subscription, int $userId): MealSubscription
    {
        return DB::transaction(function () use ($payload, $subscription, $userId) {
            $this->validateDates($payload);
            $this->validateWeekdays($payload);

            $sub = $subscription ?? new MealSubscription();
            if (! $sub->exists) {
                $sub->subscription_code = $this->codeService->generate();
            }

            $sub->fill([
                'customer_id' => $payload['customer_id'],
                'branch_id' => $payload['branch_id'],
                'status' => $payload['status'] ?? 'active',
                'start_date' => $payload['start_date'],
                'end_date' => $payload['end_date'] ?? null,
                'default_order_type' => $payload['default_order_type'] ?? 'Delivery',
                'delivery_time' => $payload['delivery_time'] ?? null,
                'address_snapshot' => $payload['address_snapshot'] ?? null,
                'phone_snapshot' => $payload['phone_snapshot'] ?? null,
                'preferred_role' => $payload['preferred_role'] ?? 'main',
                'include_salad' => $payload['include_salad'] ?? true,
                'include_dessert' => $payload['include_dessert'] ?? true,
                'notes' => $payload['notes'] ?? null,
            ]);
            $sub->created_by = $sub->created_by ?? $userId;
            $sub->save();

            // sync weekdays
            $sub->days()->delete();
            foreach ($payload['weekdays'] as $wd) {
                MealSubscriptionDay::create([
                    'subscription_id' => $sub->id,
                    'weekday' => $wd,
                ]);
            }

            return $sub->fresh(['days', 'pauses']);
        });
    }

    public function pause(MealSubscription $subscription, array $payload, int $userId): MealSubscription
    {
        $payload = $this->validatePause($payload);

        if ($subscription->status === 'active') {
            $subscription->status = 'paused';
            $subscription->save();
        }

        MealSubscriptionPause::create([
            'subscription_id' => $subscription->id,
            'pause_start' => $payload['pause_start'],
            'pause_end' => $payload['pause_end'],
            'reason' => $payload['reason'] ?? null,
            'created_by' => $userId,
        ]);

        return $subscription->fresh(['pauses']);
    }

    public function resume(MealSubscription $subscription): MealSubscription
    {
        if ($subscription->status === 'paused') {
            $subscription->status = 'active';
            $subscription->save();
        }
        return $subscription->fresh();
    }

    public function cancel(MealSubscription $subscription): MealSubscription
    {
        $subscription->status = 'cancelled';
        $subscription->save();

        return $subscription->fresh();
    }

    private function validateDates(array $payload): void
    {
        $start = $payload['start_date'] ?? null;
        $end = $payload['end_date'] ?? null;

        if (! $start) {
            throw ValidationException::withMessages(['start_date' => __('Start date is required.')]);
        }
        if ($end && \Carbon\Carbon::parse($end)->lt(\Carbon\Carbon::parse($start))) {
            throw ValidationException::withMessages(['end_date' => __('End date must be after start date.')]);
        }
    }

    private function validateWeekdays(array $payload): void
    {
        $weekdays = $payload['weekdays'] ?? [];
        if (empty($weekdays)) {
            throw ValidationException::withMessages(['weekdays' => __('Select at least one weekday.')]);
        }
    }

    private function validatePause(array $payload): array
    {
        $start = $payload['pause_start'] ?? null;
        $end = $payload['pause_end'] ?? null;
        if (! $start || ! $end) {
            throw ValidationException::withMessages(['pause_start' => __('Pause start/end required.')]);
        }
        if (\Carbon\Carbon::parse($end)->lt(\Carbon\Carbon::parse($start))) {
            throw ValidationException::withMessages(['pause_end' => __('Pause end must be after start.')]);
        }

        return $payload;
    }
}


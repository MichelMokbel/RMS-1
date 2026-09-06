<?php

namespace App\Services\Subscriptions;

use App\Jobs\SendMembershipBookingConfirmation;
use App\Models\ArInvoice;
use App\Models\MealSubscription;
use App\Models\MealSubscriptionDay;
use App\Models\MealSubscriptionOrder;
use App\Models\MealSubscriptionPause;
use App\Services\AR\ArInvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class MealSubscriptionService
{
    public function __construct(
        protected MealSubscriptionCodeService $codeService,
        protected MembershipBookingFundingService $membershipFunding,
        protected ArInvoiceService $invoices,
    ) {}

    public function save(array $payload, ?MealSubscription $subscription, int $userId): MealSubscription
    {
        if ($subscription?->fulfillment_mode === 'customer_selection') {
            throw ValidationException::withMessages([
                'subscription' => __('Paid membership subscriptions cannot be edited through the standing subscription form.'),
            ]);
        }
        Log::debug('meal_subscription.save.start', [
            'subscription_id' => $subscription?->id,
            'user_id' => $userId,
            'payload' => $this->debugPayload($payload),
        ]);

        try {
            $saved = DB::transaction(function () use ($payload, $subscription, $userId) {
                $this->validateDates($payload);
                $this->validateWeekdays($payload);

                $sub = $subscription ?? new MealSubscription;
                $isNew = ! $sub->exists;
                if ($isNew) {
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
                    'plan_meals_total' => $payload['plan_meals_total'] ?? null,
                ]);
                $sub->created_by = $sub->created_by ?? $userId;

                if ($isNew) {
                    $sub->meals_used = (int) ($payload['meals_used'] ?? 0);
                }

                $sub->save();

                $sub->days()->delete();
                foreach ($payload['weekdays'] as $wd) {
                    MealSubscriptionDay::create([
                        'subscription_id' => $sub->id,
                        'weekday' => $wd,
                    ]);
                }

                return $sub->fresh(['days', 'pauses']);
            });

            Log::debug('meal_subscription.save.success', [
                'subscription_id' => $saved->id,
                'subscription_code' => $saved->subscription_code,
                'status' => $saved->status,
            ]);

            return $saved;
        } catch (Throwable $e) {
            Log::error('meal_subscription.save.failed', [
                'subscription_id' => $subscription?->id,
                'user_id' => $userId,
                'payload' => $this->debugPayload($payload),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function pause(MealSubscription $subscription, array $payload, int $userId): MealSubscription
    {
        $payload = $this->validatePause($payload);

        return DB::transaction(function () use ($subscription, $payload, $userId): MealSubscription {
            $subject = MealSubscription::query()->findOrFail($subscription->id);
            $roots = $subject->fulfillment_mode === 'customer_selection'
                ? $this->membershipFunding->lockQueueForSubscriptionMutation($subject)
                : collect();
            $locked = MealSubscription::query()->lockForUpdate()->findOrFail($subject->id);
            if ($locked->fulfillment_mode === 'customer_selection') {
                $today = CarbonImmutable::now('Asia/Qatar')->toDateString();
                $mappings = MealSubscriptionOrder::query()
                    ->with(['funding', 'order'])
                    ->whereIn('subscription_id', $roots->pluck('id'))
                    ->whereDate('service_date', '>', $today)
                    ->whereDate('service_date', '>=', $payload['pause_start'])
                    ->whereDate('service_date', '<=', $payload['pause_end'])
                    ->whereHas('funding', fn ($query) => $query->whereIn('state', ['reserved', 'invoiced']))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($mappings as $mapping) {
                    $invoiceIds = $mapping->funding
                        ->whereIn('state', ['reserved', 'invoiced'])
                        ->pluck('invoice_id')
                        ->filter()
                        ->unique();
                    if ($invoiceIds->count() !== 1) {
                        throw ValidationException::withMessages([
                            'pause' => __('A membership booking in this period requires financial review before pausing.'),
                        ]);
                    }
                    $invoice = ArInvoice::query()->findOrFail((int) $invoiceIds->first());
                    $this->invoices->void($invoice, $userId, __('Membership paused for this service date.'));
                    $snapshot = is_array($mapping->notification_snapshots) ? $mapping->notification_snapshots : [];
                    $snapshot['cancelled_at'] = now()->toIso8601String();
                    $snapshot['cancellation_reason'] = 'membership_pause';
                    $dispatch = is_array($mapping->notification_dispatch) ? $mapping->notification_dispatch : [];
                    $dispatch['customer_cancellation'] = ['state' => 'pending'];
                    $mapping->update([
                        'notification_snapshots' => $snapshot,
                        'notification_dispatch' => $dispatch,
                    ]);
                    DB::afterCommit(fn () => SendMembershipBookingConfirmation::dispatch((int) $mapping->id, 'cancelled'));
                }
            }

            if ($locked->status === 'active') {
                $locked->status = 'paused';
                $locked->save();
            }

            MealSubscriptionPause::query()->create([
                'subscription_id' => $locked->id,
                'pause_start' => $payload['pause_start'],
                'pause_end' => $payload['pause_end'],
                'reason' => $payload['reason'] ?? null,
                'created_by' => $userId,
            ]);

            return $locked->fresh(['pauses']);
        }, 3);
    }

    public function resume(MealSubscription $subscription, ?int $userId = null): MealSubscription
    {
        return DB::transaction(function () use ($subscription, $userId): MealSubscription {
            $subject = MealSubscription::query()->findOrFail($subscription->id);
            if ($subject->fulfillment_mode === 'customer_selection') {
                $this->membershipFunding->lockQueueForSubscriptionMutation($subject);
            }
            $locked = MealSubscription::query()->lockForUpdate()->findOrFail($subject->id);
            if ($locked->status === 'paused') {
                $locked->status = 'active';
                $locked->save();
            }
            MealSubscriptionPause::query()
                ->where('subscription_id', $locked->id)
                ->whereNull('resumed_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each->update([
                    'resumed_at' => now(),
                    'resumed_by' => $userId,
                ]);

            return $locked->fresh(['pauses']);
        }, 3);
    }

    public function cancel(MealSubscription $subscription): MealSubscription
    {
        if ($subscription->fulfillment_mode === 'customer_selection') {
            throw ValidationException::withMessages([
                'subscription' => __('Paid membership subscriptions cannot be cancelled through the standing subscription action.'),
            ]);
        }
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

    private function debugPayload(array $payload): array
    {
        return [
            'customer_id' => $payload['customer_id'] ?? null,
            'branch_id' => $payload['branch_id'] ?? null,
            'status' => $payload['status'] ?? null,
            'start_date' => $payload['start_date'] ?? null,
            'end_date' => $payload['end_date'] ?? null,
            'default_order_type' => $payload['default_order_type'] ?? null,
            'delivery_time' => $payload['delivery_time'] ?? null,
            'phone_snapshot' => $payload['phone_snapshot'] ?? null,
            'preferred_role' => $payload['preferred_role'] ?? null,
            'include_salad' => $payload['include_salad'] ?? null,
            'include_dessert' => $payload['include_dessert'] ?? null,
            'plan_meals_total' => $payload['plan_meals_total'] ?? null,
            'weekdays' => array_values($payload['weekdays'] ?? []),
            'has_address_snapshot' => filled($payload['address_snapshot'] ?? null),
            'has_notes' => filled($payload['notes'] ?? null),
        ];
    }
}

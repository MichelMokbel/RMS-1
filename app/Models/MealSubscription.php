<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Payment;

class MealSubscription extends Model
{
    use HasFactory;

    protected $table = 'meal_subscriptions';

    protected $fillable = [
        'subscription_code',
        'customer_id',
        'branch_id',
        'status',
        'start_date',
        'end_date',
        'plan_meals_total',
        'meals_used',
        'meal_plan_request_id',
        'default_order_type',
        'delivery_time',
        'address_snapshot',
        'phone_snapshot',
        'preferred_role',
        'include_salad',
        'include_dessert',
        'notes',
        'created_by',
        'source_payment_id',
        'uses_invoice_tracking',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'include_salad' => 'boolean',
        'include_dessert' => 'boolean',
        'plan_meals_total' => 'integer',
        'meals_used' => 'integer',
        'meal_plan_request_id' => 'integer',
        'source_payment_id' => 'integer',
        'uses_invoice_tracking' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function sourcePayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'source_payment_id');
    }

    public function subscriptionOrders(): HasMany
    {
        return $this->hasMany(MealSubscriptionOrder::class, 'subscription_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(MealSubscriptionDay::class, 'subscription_id');
    }

    public function pauses(): HasMany
    {
        return $this->hasMany(MealSubscriptionPause::class, 'subscription_id');
    }

    public function isActiveOn($date): bool
    {
        $date = \Illuminate\Support\Carbon::parse($date);
        if ($this->status !== 'active') {
            return false;
        }
        if ($date->lt($this->start_date)) {
            return false;
        }
        if ($this->end_date && $date->gt($this->end_date)) {
            return false;
        }
        // Pause ranges
        foreach ($this->pauses as $pause) {
            if ($date->greaterThanOrEqualTo($pause->pause_start) && $date->lessThanOrEqualTo($pause->pause_end)) {
                return false;
            }
        }
        // Weekday enabled
        $weekday = (int) $date->format('N'); // 1-7
        return $this->weekdayEnabled($weekday);
    }

    public function weekdayEnabled(int $weekday): bool
    {
        return $this->days->contains('weekday', $weekday);
    }
}

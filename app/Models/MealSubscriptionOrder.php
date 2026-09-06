<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealSubscriptionOrder extends Model
{
    use HasFactory;

    protected $table = 'meal_subscription_orders';

    public $timestamps = false;

    protected $fillable = [
        'subscription_id',
        'order_id',
        'service_date',
        'branch_id',
        'booking_uuid',
        'booking_revision',
        'supersedes_subscription_order_id',
        'accepted_operation_uuid',
        'notification_snapshots',
        'notification_dispatch',
    ];

    protected $casts = [
        'service_date' => 'date',
        'booking_revision' => 'integer',
        'supersedes_subscription_order_id' => 'integer',
        'notification_snapshots' => 'encrypted:array',
        'notification_dispatch' => 'array',
        'created_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MealSubscription::class, 'subscription_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function funding(): HasMany
    {
        return $this->hasMany(MembershipBookingFunding::class, 'subscription_order_id');
    }
}

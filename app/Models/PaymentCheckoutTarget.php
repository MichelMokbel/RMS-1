<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentCheckoutTarget extends Model
{
    protected $fillable = [
        'attempt_id',
        'sequence',
        'target_type',
        'service_date',
        'expected_amount_cents',
        'item_snapshot',
        'hold_state',
        'held_at',
        'activated_at',
        'released_at',
        'intended_invoice_issue_date',
        'order_id',
        'meal_plan_request_id',
        'membership_subscription_id',
        'membership_main_quantity',
        'invoice_id',
    ];

    protected $casts = [
        'attempt_id' => 'integer',
        'sequence' => 'integer',
        'service_date' => 'date',
        'expected_amount_cents' => 'integer',
        'item_snapshot' => 'encrypted:array',
        'held_at' => 'datetime',
        'activated_at' => 'datetime',
        'released_at' => 'datetime',
        'intended_invoice_issue_date' => 'date',
        'order_id' => 'integer',
        'meal_plan_request_id' => 'integer',
        'membership_subscription_id' => 'integer',
        'membership_main_quantity' => 'integer',
        'invoice_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentCheckoutAttempt::class, 'attempt_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class, 'invoice_id');
    }

    public function mealPlanRequest(): BelongsTo
    {
        return $this->belongsTo(MealPlanRequest::class, 'meal_plan_request_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentCheckoutTargetItem::class, 'target_id')->orderBy('sequence');
    }
}

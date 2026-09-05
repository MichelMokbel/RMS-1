<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipPurchaseBlock extends Model
{
    protected $fillable = [
        'subscription_id',
        'plan_id',
        'payment_id',
        'meal_plan_request_id',
        'company_id',
        'branch_id',
        'original_customer_id',
        'queue_position',
        'meal_count',
        'gross_price_cents',
        'discount_cents',
        'final_price_cents',
        'currency',
        'origin',
        'origin_key',
        'quote_fingerprint',
        'pricing_snapshot',
        'terms_snapshot',
        'opening_used_quantity',
        'opening_released_quantity',
        'opening_used_ranges',
        'opening_released_ranges',
        'legacy_evidence_snapshot',
        'legacy_cutover_at',
        'funded_at',
        'cancelled_at',
        'created_by',
        'cancelled_by',
    ];

    protected $casts = [
        'subscription_id' => 'integer',
        'plan_id' => 'integer',
        'payment_id' => 'integer',
        'meal_plan_request_id' => 'integer',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'original_customer_id' => 'integer',
        'queue_position' => 'integer',
        'meal_count' => 'integer',
        'gross_price_cents' => 'integer',
        'discount_cents' => 'integer',
        'final_price_cents' => 'integer',
        'pricing_snapshot' => 'encrypted:array',
        'terms_snapshot' => 'encrypted:array',
        'opening_used_quantity' => 'integer',
        'opening_released_quantity' => 'integer',
        'opening_used_ranges' => 'array',
        'opening_released_ranges' => 'array',
        'legacy_evidence_snapshot' => 'encrypted:array',
        'legacy_cutover_at' => 'datetime',
        'funded_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'created_by' => 'integer',
        'cancelled_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MealSubscription::class, 'subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'plan_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MealPlanRequest::class, 'meal_plan_request_id');
    }
}

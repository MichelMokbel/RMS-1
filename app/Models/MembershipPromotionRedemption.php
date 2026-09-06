<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipPromotionRedemption extends Model
{
    public const KIND_PAID_PURCHASE = 'paid_purchase';

    public const KIND_ZERO_REQUEST = 'zero_request';

    protected $fillable = [
        'promotion_id',
        'company_id',
        'branch_id',
        'original_customer_id',
        'original_user_id',
        'kind',
        'checkout_id',
        'reservation_id',
        'meal_plan_request_id',
        'purchase_block_id',
        'offer_snapshot',
        'eligibility_snapshot',
        'gross_cents',
        'discount_cents',
        'net_cents',
        'redeemed_at',
        'zero_subject_key',
    ];

    protected $casts = [
        'promotion_id' => 'integer',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'original_customer_id' => 'integer',
        'original_user_id' => 'integer',
        'checkout_id' => 'integer',
        'reservation_id' => 'integer',
        'meal_plan_request_id' => 'integer',
        'purchase_block_id' => 'integer',
        'offer_snapshot' => 'encrypted:array',
        'eligibility_snapshot' => 'encrypted:array',
        'gross_cents' => 'integer',
        'discount_cents' => 'integer',
        'net_cents' => 'integer',
        'redeemed_at' => UtcDateTime::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(MembershipPromotion::class, 'promotion_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function originalCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'original_customer_id');
    }

    public function originalUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'original_user_id');
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(PaymentCheckoutAttempt::class, 'checkout_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(MembershipPromotionReservation::class, 'reservation_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MealPlanRequest::class, 'meal_plan_request_id');
    }

    public function purchaseBlock(): BelongsTo
    {
        return $this->belongsTo(MembershipPurchaseBlock::class, 'purchase_block_id');
    }
}

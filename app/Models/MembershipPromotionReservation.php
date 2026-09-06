<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipPromotionReservation extends Model
{
    public const STATUS_HELD = 'held';

    public const STATUS_REDEEMED = 'redeemed';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'promotion_id',
        'company_id',
        'branch_id',
        'original_customer_id',
        'original_user_id',
        'checkout_id',
        'status',
        'offer_snapshot',
        'eligibility_snapshot',
        'gross_cents',
        'discount_cents',
        'net_cents',
        'starts_at',
        'expires_at',
        'redeemed_at',
        'released_at',
        'release_reason',
    ];

    protected $casts = [
        'promotion_id' => 'integer',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'original_customer_id' => 'integer',
        'original_user_id' => 'integer',
        'checkout_id' => 'integer',
        'offer_snapshot' => 'encrypted:array',
        'eligibility_snapshot' => 'encrypted:array',
        'gross_cents' => 'integer',
        'discount_cents' => 'integer',
        'net_cents' => 'integer',
        'starts_at' => UtcDateTime::class,
        'expires_at' => UtcDateTime::class,
        'redeemed_at' => UtcDateTime::class,
        'released_at' => UtcDateTime::class,
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
}

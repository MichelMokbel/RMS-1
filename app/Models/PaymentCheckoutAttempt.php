<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentCheckoutAttempt extends Model
{
    protected $fillable = [
        'reference',
        'company_id',
        'branch_id',
        'customer_id',
        'portal_user_id',
        'payment_source_id',
        'client_uuid',
        'purpose',
        'currency',
        'gross_amount_cents',
        'discount_amount_cents',
        'payable_amount_cents',
        'cart_fingerprint',
        'quote_fingerprint',
        'request_fingerprint',
        'recovery_fingerprint',
        'state',
        'started_at',
        'expires_at',
        'completed_at',
        'cart_snapshot',
        'customer_snapshot',
        'pricing_snapshot',
        'terms_snapshot',
        'request_snapshot',
        'notification_snapshots',
        'source_account_snapshot',
        'notification_dispatch',
        'operations_tracking',
        'financial_intent',
        'provider_request_uuid',
        'provider_create_outcome',
        'provider_dispatched_at',
        'last_error_code',
        'provider_detail_recovery_attempts',
        'next_recovery_at',
        'operations_next_action_at',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'customer_id' => 'integer',
        'portal_user_id' => 'integer',
        'payment_source_id' => 'integer',
        'gross_amount_cents' => 'integer',
        'discount_amount_cents' => 'integer',
        'payable_amount_cents' => 'integer',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'provider_dispatched_at' => 'datetime',
        'provider_detail_recovery_attempts' => 'integer',
        'next_recovery_at' => 'datetime',
        'operations_next_action_at' => 'datetime',
        'cart_snapshot' => 'encrypted:array',
        'customer_snapshot' => 'encrypted:array',
        'pricing_snapshot' => 'encrypted:array',
        'terms_snapshot' => 'encrypted:array',
        'request_snapshot' => 'encrypted:array',
        'notification_snapshots' => 'encrypted:array',
        'source_account_snapshot' => 'encrypted:array',
        'notification_dispatch' => 'array',
        'operations_tracking' => 'array',
        'financial_intent' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'portal_user_id');
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class, 'payment_source_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PaymentCheckoutTarget::class, 'attempt_id')->orderBy('sequence');
    }

    public function providerTransactions(): HasMany
    {
        return $this->hasMany(PaymentProviderTransaction::class, 'attempt_id');
    }

    public function promotionReservation(): HasOne
    {
        return $this->hasOne(MembershipPromotionReservation::class, 'checkout_id');
    }

    public function promotionRedemption(): HasOne
    {
        return $this->hasOne(MembershipPromotionRedemption::class, 'checkout_id');
    }
}

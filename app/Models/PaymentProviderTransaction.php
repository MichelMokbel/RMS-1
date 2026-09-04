<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentProviderTransaction extends Model
{
    protected $fillable = [
        'attempt_id',
        'payment_source_id',
        'provider_payment_id',
        'merchant_transaction_id',
        'amount_cents',
        'currency',
        'raw_status',
        'normalized_status',
        'finished_at',
        'finished_at_evidence_source',
        'verified_paid_at',
        'verified_amount_cents',
        'verified_currency',
        'verified_finished_at',
        'details_checked_at',
        'pay_url',
        'provider_expires_at',
        'visa_id',
        'card_type',
        'classification',
        'receipt_date',
        'receipt_client_uuid',
        'payment_id',
        'active_clearing_settlement_id',
    ];

    protected $casts = [
        'attempt_id' => 'integer',
        'payment_source_id' => 'integer',
        'amount_cents' => 'integer',
        'finished_at' => 'datetime',
        'verified_paid_at' => 'datetime',
        'verified_amount_cents' => 'integer',
        'verified_finished_at' => 'datetime',
        'details_checked_at' => 'datetime',
        'pay_url' => 'encrypted',
        'provider_expires_at' => 'datetime',
        'receipt_date' => 'date',
        'payment_id' => 'integer',
        'active_clearing_settlement_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentCheckoutAttempt::class, 'attempt_id');
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class, 'payment_source_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentProviderEvent::class, 'provider_transaction_id');
    }

    public function activeClearingSettlement(): BelongsTo
    {
        return $this->belongsTo(ArClearingSettlement::class, 'active_clearing_settlement_id');
    }
}

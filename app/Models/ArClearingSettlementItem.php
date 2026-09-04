<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ArClearingSettlementItem extends Model
{
    protected $fillable = [
        'settlement_id',
        'payment_id',
        'provider_transaction_id',
        'gateway_settlement_row_id',
        'amount_cents',
    ];

    protected $casts = [
        'settlement_id' => 'integer',
        'payment_id' => 'integer',
        'provider_transaction_id' => 'integer',
        'gateway_settlement_row_id' => 'integer',
        'amount_cents' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw ValidationException::withMessages([
                'item' => __('AR clearing settlement items are immutable after creation.'),
            ]);
        });

        static::deleting(function () {
            throw ValidationException::withMessages([
                'item' => __('AR clearing settlement items cannot be deleted.'),
            ]);
        });
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ArClearingSettlement::class, 'settlement_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function providerTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderTransaction::class, 'provider_transaction_id');
    }

    public function sourceRow(): BelongsTo
    {
        return $this->belongsTo(GatewaySettlementRow::class, 'gateway_settlement_row_id');
    }
}

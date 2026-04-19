<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArClearingSettlementItem extends Model
{
    protected $fillable = ['settlement_id', 'payment_id', 'amount_cents'];

    protected $casts = ['amount_cents' => 'integer'];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ArClearingSettlement::class, 'settlement_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

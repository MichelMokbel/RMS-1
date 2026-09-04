<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ArClearingSettlementAdjustment extends Model
{
    protected $fillable = [
        'settlement_id',
        'payment_source_id',
        'expense_account_id',
        'gateway_settlement_row_id',
        'adjustment_type',
        'economic_identity',
        'amount_cents',
        'account_snapshot',
        'evidence_snapshot',
    ];

    protected $hidden = ['account_snapshot', 'evidence_snapshot'];

    protected $casts = [
        'settlement_id' => 'integer',
        'payment_source_id' => 'integer',
        'expense_account_id' => 'integer',
        'gateway_settlement_row_id' => 'integer',
        'amount_cents' => 'integer',
        'account_snapshot' => 'encrypted:array',
        'evidence_snapshot' => 'encrypted:array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages([
            'adjustment' => __('Settlement adjustments are immutable after posting.'),
        ]));

        static::deleting(fn () => throw ValidationException::withMessages([
            'adjustment' => __('Settlement adjustments cannot be deleted.'),
        ]));
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ArClearingSettlement::class, 'settlement_id');
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class, 'payment_source_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'expense_account_id');
    }

    public function sourceRow(): BelongsTo
    {
        return $this->belongsTo(GatewaySettlementRow::class, 'gateway_settlement_row_id');
    }
}

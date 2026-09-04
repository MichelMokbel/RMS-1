<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ArClearingSettlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'payment_source_id',
        'gateway_import_id',
        'bank_account_id',
        'evidence_bank_transaction_id',
        'settlement_method',
        'settlement_date',
        'amount_cents',
        'commission_cents',
        'settlement_fee_cents',
        'net_cents',
        'client_uuid',
        'reference',
        'payout_reference',
        'reviewed_fingerprint',
        'evidence_snapshot',
        'original_clearing_breakdown',
        'commission_expense_account_id',
        'settlement_fee_expense_account_id',
        'bank_ledger_account_id',
        'notes',
        'created_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'settlement_date' => 'date',
        'voided_at' => 'datetime',
        'amount_cents' => 'integer',
        'payment_source_id' => 'integer',
        'gateway_import_id' => 'integer',
        'evidence_bank_transaction_id' => 'integer',
        'commission_cents' => 'integer',
        'settlement_fee_cents' => 'integer',
        'net_cents' => 'integer',
        'evidence_snapshot' => 'encrypted:array',
        'original_clearing_breakdown' => 'encrypted:array',
        'commission_expense_account_id' => 'integer',
        'settlement_fee_expense_account_id' => 'integer',
        'bank_ledger_account_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (ArClearingSettlement $model) {
            $allowed = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];
            foreach (array_keys($model->getDirty()) as $field) {
                if (! in_array($field, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'settlement' => __('AR clearing settlements are immutable after creation.'),
                    ]);
                }
            }
        });

        static::deleting(function (ArClearingSettlement $model) {
            Log::error('[ArClearingSettlement] Hard-delete blocked on settlement #'.$model->id.'. Void instead.');
            throw ValidationException::withMessages([
                'settlement' => __('AR clearing settlements cannot be hard-deleted. Void them instead.'),
            ]);
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(ArClearingSettlementItem::class, 'settlement_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class, 'payment_source_id');
    }

    public function gatewayImport(): BelongsTo
    {
        return $this->belongsTo(GatewaySettlementImport::class, 'gateway_import_id');
    }

    public function evidenceBankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'evidence_bank_transaction_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(ArClearingSettlementAdjustment::class, 'settlement_id');
    }
}

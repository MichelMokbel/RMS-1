<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewaySettlementRow extends Model
{
    protected $fillable = [
        'import_id',
        'payment_source_id',
        'row_sequence',
        'worksheet',
        'physical_row',
        'order_type',
        'status',
        'payout_reference',
        'row_reference',
        'economic_identity',
        'financial_content_hash',
        'source_evidence',
        'customer_phone',
        'customer_phone_hash',
        'merchant',
        'branch_code',
        'branch_id',
        'transaction_at',
        'explicit_bank_date',
        'sales_cents',
        'gross_cents',
        'variable_commission_cents',
        'fixed_commission_cents',
        'total_commission_cents',
        'settlement_fee_cents',
        'net_cents',
        'match_state',
        'matched_provider_transaction_id',
        'duplicate_of_row_id',
        'evidence_reference',
        'reviewed_by',
        'reviewed_at',
        'match_reason',
        'error_code',
        'revision',
    ];

    protected $hidden = ['source_evidence', 'customer_phone'];

    protected $casts = [
        'import_id' => 'integer',
        'payment_source_id' => 'integer',
        'row_sequence' => 'integer',
        'physical_row' => 'integer',
        'source_evidence' => 'encrypted:array',
        'customer_phone' => 'encrypted',
        'branch_id' => 'integer',
        'transaction_at' => 'datetime',
        'explicit_bank_date' => 'date',
        'sales_cents' => 'integer',
        'gross_cents' => 'integer',
        'variable_commission_cents' => 'integer',
        'fixed_commission_cents' => 'integer',
        'total_commission_cents' => 'integer',
        'settlement_fee_cents' => 'integer',
        'net_cents' => 'integer',
        'matched_provider_transaction_id' => 'integer',
        'duplicate_of_row_id' => 'integer',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'revision' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(GatewaySettlementImport::class, 'import_id');
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class, 'payment_source_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function providerTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderTransaction::class, 'matched_provider_transaction_id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_row_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GatewaySettlementImport extends Model
{
    protected $fillable = [
        'company_id',
        'payment_source_id',
        'imported_by',
        'private_disk',
        'object_key',
        'original_name',
        'file_hash',
        'parser_version',
        'currency',
        'timezone',
        'report_period_start',
        'report_period_end',
        'gross_cents',
        'commission_cents',
        'settlement_fee_cents',
        'net_cents',
        'totals_complete',
        'review_state',
        'posting_state',
        'revision',
        'review_snapshots',
        'evidence_manifest',
        'saved_post_operations',
        'reviewed_by',
        'reviewed_at',
        'error_code',
    ];

    protected $hidden = [
        'object_key',
        'review_snapshots',
        'evidence_manifest',
        'saved_post_operations',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'payment_source_id' => 'integer',
        'imported_by' => 'integer',
        'report_period_start' => 'date',
        'report_period_end' => 'date',
        'gross_cents' => 'integer',
        'commission_cents' => 'integer',
        'settlement_fee_cents' => 'integer',
        'net_cents' => 'integer',
        'totals_complete' => 'boolean',
        'revision' => 'integer',
        'review_snapshots' => 'encrypted:array',
        'evidence_manifest' => 'encrypted:array',
        'saved_post_operations' => 'encrypted:array',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class, 'payment_source_id');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(GatewaySettlementRow::class, 'import_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(ArClearingSettlement::class, 'gateway_import_id');
    }
}

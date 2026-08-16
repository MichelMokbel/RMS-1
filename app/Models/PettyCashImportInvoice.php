<?php

namespace App\Models;

use App\Enums\PettyCash\PettyCashImportInvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashImportInvoice extends Model
{
    protected $fillable = [
        'import_batch_id',
        'entry_id',
        'group_key',
        'status',
        'header',
        'errors',
        'client_uuid',
        'target_invoice_id',
    ];

    protected $casts = [
        'import_batch_id' => 'integer',
        'status' => PettyCashImportInvoiceStatus::class,
        'header' => 'encrypted:array',
        'errors' => 'encrypted:array',
        'target_invoice_id' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PettyCashImportBatch::class, 'import_batch_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PettyCashImportRow::class, 'import_invoice_id');
    }

    public function targetInvoice(): BelongsTo
    {
        return $this->belongsTo(ApInvoice::class, 'target_invoice_id');
    }
}

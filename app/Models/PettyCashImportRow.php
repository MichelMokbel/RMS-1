<?php

namespace App\Models;

use App\Enums\PettyCash\PettyCashImportRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PettyCashImportRow extends Model
{
    protected $fillable = [
        'import_batch_id',
        'import_invoice_id',
        'row_number',
        'source_identifier',
        'status',
        'payload',
        'errors',
        'row_hash',
        'target_type',
        'target_id',
    ];

    protected $casts = [
        'import_batch_id' => 'integer',
        'import_invoice_id' => 'integer',
        'row_number' => 'integer',
        'status' => PettyCashImportRowStatus::class,
        'payload' => 'encrypted:array',
        'errors' => 'encrypted:array',
        'target_id' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PettyCashImportBatch::class, 'import_batch_id');
    }

    public function importInvoice(): BelongsTo
    {
        return $this->belongsTo(PettyCashImportInvoice::class, 'import_invoice_id');
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}

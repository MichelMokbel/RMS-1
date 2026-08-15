<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class HrImportRow extends Model
{
    protected $fillable = [
        'import_batch_id', 'row_number', 'source_identifier', 'status', 'payload',
        'errors', 'row_hash', 'target_type', 'target_id',
    ];

    protected $casts = [
        'import_batch_id' => 'integer', 'row_number' => 'integer', 'payload' => 'encrypted:array',
        'errors' => 'encrypted:array', 'target_id' => 'integer',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(HrImportBatch::class, 'import_batch_id');
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}

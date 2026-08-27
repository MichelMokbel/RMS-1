<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashImportEditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'import_batch_id', 'import_invoice_id', 'import_row_id', 'actor_id',
        'action', 'before_values', 'after_values', 'created_at',
    ];

    protected $casts = [
        'import_batch_id' => 'integer',
        'import_invoice_id' => 'integer',
        'import_row_id' => 'integer',
        'actor_id' => 'integer',
        'before_values' => 'encrypted:array',
        'after_values' => 'encrypted:array',
        'created_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PettyCashImportBatch::class, 'import_batch_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlBatchLine extends Model
{
    use HasFactory;

    protected $table = 'gl_batch_lines';

    protected $fillable = [
        'batch_id',
        'account_id',
        'debit_total',
        'credit_total',
    ];

    protected $casts = [
        'debit_total' => 'decimal:4',
        'credit_total' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(GlBatch::class, 'batch_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }
}

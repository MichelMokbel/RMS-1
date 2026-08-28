<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashImportCategoryProposal extends Model
{
    protected $fillable = [
        'import_batch_id', 'source_code', 'source_name', 'normalized_name', 'is_declared',
        'status', 'expense_category_id',
    ];

    protected $casts = [
        'import_batch_id' => 'integer',
        'expense_category_id' => 'integer',
        'is_declared' => 'boolean',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PettyCashImportBatch::class, 'import_batch_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}

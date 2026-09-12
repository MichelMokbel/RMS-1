<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderSheet extends Model
{
    protected $fillable = ['sheet_date', 'excluded_order_ids'];

    protected $casts = [
        'sheet_date' => 'date',
        'excluded_order_ids' => 'array',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(OrderSheetEntry::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderSheet extends Model
{
    protected $fillable = ['sheet_date'];

    protected $casts = ['sheet_date' => 'date'];

    public function entries(): HasMany
    {
        return $this->hasMany(OrderSheetEntry::class);
    }
}

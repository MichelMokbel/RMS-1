<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSheetEntryExtra extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_sheet_entry_id', 'menu_item_id', 'menu_item_name', 'quantity'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(OrderSheetEntry::class, 'order_sheet_entry_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }
}

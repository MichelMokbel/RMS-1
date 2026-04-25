<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSheetEntryQuantity extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_sheet_entry_id', 'daily_dish_menu_item_id', 'quantity'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(OrderSheetEntry::class, 'order_sheet_entry_id');
    }

    public function dailyDishMenuItem(): BelongsTo
    {
        return $this->belongsTo(DailyDishMenuItem::class, 'daily_dish_menu_item_id');
    }
}

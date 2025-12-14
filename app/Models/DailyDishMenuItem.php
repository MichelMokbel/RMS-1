<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyDishMenuItem extends Model
{
    use HasFactory;

    protected $table = 'daily_dish_menu_items';

    public $timestamps = false;

    protected $fillable = [
        'daily_dish_menu_id',
        'menu_item_id',
        'role',
        'sort_order',
        'is_required',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
    ];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(DailyDishMenu::class, 'daily_dish_menu_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}


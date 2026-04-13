<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PastryOrderItem extends Model
{
    use HasFactory;

    protected $table = 'pastry_order_items';

    public $timestamps = false;

    protected $fillable = [
        'pastry_order_id',
        'menu_item_id',
        'description_snapshot',
        'quantity',
        'unit_price',
        'discount_amount',
        'line_total',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'quantity'        => 'decimal:3',
        'unit_price'      => 'decimal:3',
        'discount_amount' => 'decimal:3',
        'line_total'      => 'decimal:3',
        'sort_order'      => 'integer',
    ];

    public function pastryOrder(): BelongsTo
    {
        return $this->belongsTo(PastryOrder::class, 'pastry_order_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}

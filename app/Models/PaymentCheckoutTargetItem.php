<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentCheckoutTargetItem extends Model
{
    protected $fillable = [
        'target_id',
        'sequence',
        'line_role',
        'menu_item_id',
        'title',
        'description',
        'unit',
        'quantity',
        'unit_price_cents',
        'line_total_cents',
    ];

    protected $casts = [
        'target_id' => 'integer',
        'sequence' => 'integer',
        'menu_item_id' => 'integer',
        'quantity' => 'decimal:3',
        'unit_price_cents' => 'integer',
        'line_total_cents' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function target(): BelongsTo
    {
        return $this->belongsTo(PaymentCheckoutTarget::class, 'target_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}

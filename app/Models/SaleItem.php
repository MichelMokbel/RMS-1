<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SaleItem extends Model
{
    use HasFactory;

    protected $table = 'sale_items';

    protected $fillable = [
        'sale_id',
        'sellable_type',
        'sellable_id',
        'name_snapshot',
        'sku_snapshot',
        'tax_rate_bps',
        'qty',
        'unit_price_cents',
        'discount_cents',
        'discount_type',
        'discount_value',
        'tax_cents',
        'line_total_cents',
        'meta',
        'note',
        'sort_order',
    ];

    protected $casts = [
        'tax_rate_bps' => 'integer',
        'qty' => 'decimal:3',
        'unit_price_cents' => 'integer',
        'discount_cents' => 'integer',
        'discount_value' => 'integer',
        'tax_cents' => 'integer',
        'line_total_cents' => 'integer',
        'meta' => 'array',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function sellable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'sellable_type', 'sellable_id');
    }
}


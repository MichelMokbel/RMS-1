<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    protected $fillable = [
        'quotation_id', 'menu_item_id', 'description', 'unit', 'quantity',
        'unit_price_cents', 'unit_price_minor', 'discount_cents', 'discount_minor',
        'line_total_cents', 'line_total_minor', 'sort_order', 'catalog_snapshot',
    ];

    protected $appends = ['unit_price_minor', 'discount_minor', 'line_total_minor'];

    protected $casts = [
        'quotation_id' => 'integer', 'menu_item_id' => 'integer', 'quantity' => 'decimal:3',
        'unit_price_cents' => 'integer', 'discount_cents' => 'integer',
        'line_total_cents' => 'integer', 'sort_order' => 'integer', 'catalog_snapshot' => 'array',
    ];

    public function getUnitPriceMinorAttribute(): int
    {
        return (int) $this->unit_price_cents;
    }

    public function getDiscountMinorAttribute(): int
    {
        return (int) $this->discount_cents;
    }

    public function getLineTotalMinorAttribute(): int
    {
        return (int) $this->line_total_cents;
    }

    public function setUnitPriceMinorAttribute(int $value): void
    {
        $this->attributes['unit_price_cents'] = $value;
    }

    public function setDiscountMinorAttribute(int $value): void
    {
        $this->attributes['discount_cents'] = $value;
    }

    public function setLineTotalMinorAttribute(int $value): void
    {
        $this->attributes['line_total_cents'] = $value;
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Validation\ValidationException;

class DeliveryNoteItem extends Model
{
    protected $fillable = [
        'delivery_note_id', 'description', 'qty', 'unit', 'unit_price_cents', 'discount_cents',
        'tax_cents', 'sellable_type', 'sellable_id', 'name_snapshot', 'sku_snapshot', 'line_notes', 'meta',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_price_cents' => 'integer',
        'discount_cents' => 'integer',
        'tax_cents' => 'integer',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        $assertDraft = function (DeliveryNoteItem $item): void {
            if ($item->deliveryNote()->value('status') !== 'draft') {
                throw ValidationException::withMessages(['delivery_note' => __('Issued delivery note items are immutable.')]);
            }
        };

        static::creating($assertDraft);
        static::updating($assertDraft);
        static::deleting($assertDraft);
    }

    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function sellable(): MorphTo
    {
        return $this->morphTo();
    }
}

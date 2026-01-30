<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ArInvoiceItem extends Model
{
    use HasFactory;

    protected $table = 'ar_invoice_items';

    protected $fillable = [
        'invoice_id',
        'description',
        'qty',
        'unit',
        'unit_price_cents',
        'discount_cents',
        'tax_cents',
        'line_total_cents',
        'line_notes',
        'sellable_type',
        'sellable_id',
        'name_snapshot',
        'sku_snapshot',
        'meta',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit' => 'string',
        'unit_price_cents' => 'integer',
        'discount_cents' => 'integer',
        'tax_cents' => 'integer',
        'line_total_cents' => 'integer',
        'line_notes' => 'string',
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class, 'invoice_id');
    }

    public function sellable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'sellable_type', 'sellable_id');
    }
}


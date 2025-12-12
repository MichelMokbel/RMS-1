<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $table = 'purchase_order_items';
    public $timestamps = false;
    public const UPDATED_AT = null;
    public const CREATED_AT = 'created_at';

    protected $fillable = [
        'purchase_order_id',
        'item_id',
        'quantity',
        'unit_price',
        'total_price',
        'received_quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'received_quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function remainingToReceive(): int
    {
        $qty = (int) $this->quantity;
        $received = (int) ($this->received_quantity ?? 0);

        return max($qty - $received, 0);
    }
}

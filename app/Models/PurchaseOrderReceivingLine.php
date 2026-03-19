<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderReceivingLine extends Model
{
    use HasFactory;

    protected $table = 'purchase_order_receiving_lines';

    protected $fillable = [
        'purchase_order_receiving_id',
        'purchase_order_item_id',
        'inventory_item_id',
        'received_quantity',
        'unit_cost',
        'total_cost',
    ];

    protected $casts = [
        'received_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function receiving(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderReceiving::class, 'purchase_order_receiving_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}

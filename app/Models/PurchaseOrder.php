<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Supplier;
use App\Models\User;

class PurchaseOrder extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'purchase_orders';

    protected $fillable = [
        'po_number',
        'supplier_id',
        'order_date',
        'expected_delivery_date',
        'status',
        'total_amount',
        'received_date',
        'notes',
        'payment_terms',
        'payment_type',
        'created_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'received_date' => 'date',
        'total_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool { return $this->status === self::STATUS_DRAFT; }
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isApproved(): bool { return $this->status === self::STATUS_APPROVED; }
    public function isReceived(): bool { return $this->status === self::STATUS_RECEIVED; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }

    public function canEditLines(): bool
    {
        return $this->status === self::STATUS_DRAFT || $this->status === self::STATUS_PENDING;
    }

    public function canApprove(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canReceive(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isFullyReceived(): bool
    {
        if ($this->items->isEmpty()) {
            return false;
        }

        return $this->items->every(function (PurchaseOrderItem $item) {
            return (int) $item->received_quantity >= (int) $item->quantity;
        });
    }

    public function recalculateTotals(): void
    {
        $total = $this->items()->sum('total_price');
        $this->total_amount = $total;
        $this->save();
    }
}

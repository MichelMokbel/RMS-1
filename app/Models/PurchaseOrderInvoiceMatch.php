<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderInvoiceMatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'purchase_order_id',
        'purchase_order_item_id',
        'ap_invoice_id',
        'ap_invoice_item_id',
        'matched_quantity',
        'matched_amount',
        'received_value',
        'invoiced_value',
        'price_variance',
        'receipt_date',
        'invoice_date',
        'status',
        'override_applied',
        'overridden_by',
        'overridden_at',
        'override_reason',
    ];

    protected $casts = [
        'matched_quantity' => 'decimal:3',
        'matched_amount' => 'decimal:2',
        'received_value' => 'decimal:2',
        'invoiced_value' => 'decimal:2',
        'price_variance' => 'decimal:2',
        'receipt_date' => 'date',
        'invoice_date' => 'date',
        'override_applied' => 'boolean',
        'overridden_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ApInvoice::class, 'ap_invoice_id');
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(ApInvoiceItem::class, 'ap_invoice_item_id');
    }
}

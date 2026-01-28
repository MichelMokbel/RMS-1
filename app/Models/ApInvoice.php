<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Supplier;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ApInvoice extends Model
{
    use HasFactory;

    protected $table = 'ap_invoices';

    protected $fillable = [
        'supplier_id',
        'purchase_order_id',
        'category_id',
        'is_expense',
        'invoice_number',
        'invoice_date',
        'due_date',
        'subtotal',
        'tax_amount',
        'total_amount',
        'status',
        'posted_at',
        'posted_by',
        'voided_at',
        'voided_by',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'is_expense' => 'boolean',
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (ApInvoice $invoice) {
            if ($invoice->getOriginal('status') === 'draft') {
                return;
            }

            $dirty = array_keys($invoice->getDirty());
            if ($dirty === []) {
                return;
            }

            $allowed = [
                'status',
                'posted_at',
                'posted_by',
                'voided_at',
                'voided_by',
                'updated_at',
            ];

            $allowedWhenVoiding = array_merge($allowed, ['notes']);

            $isVoiding = ($invoice->status === 'void') || $invoice->isDirty('voided_at') || $invoice->isDirty('voided_by');

            $permitted = $isVoiding ? $allowedWhenVoiding : $allowed;

            foreach ($dirty as $field) {
                if (! in_array($field, $permitted, true)) {
                    throw ValidationException::withMessages([
                        'invoice' => __('Posted invoices are immutable.'),
                    ]);
                }
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ApInvoiceItem::class, 'invoice_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ApPaymentAllocation::class, 'invoice_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function paidAmount(): float
    {
        return (float) $this->allocations()->sum('allocated_amount');
    }

    public function outstandingAmount(): float
    {
        return (float) $this->total_amount - $this->paidAmount();
    }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isPosted(): bool { return $this->status === 'posted'; }
    public function isPartiallyPaid(): bool { return $this->status === 'partially_paid'; }
    public function isPaid(): bool { return $this->status === 'paid'; }
    public function isVoid(): bool { return $this->status === 'void'; }

    public function canPost(): bool
    {
        return $this->isDraft() && $this->supplier_id && $this->items()->count() > 0;
    }

    public function canVoid(): bool
    {
        return in_array($this->status, ['draft', 'posted'], true) && $this->allocations()->count() === 0;
    }

    public function isOverdue(): bool
    {
        return ! $this->isVoid()
            && ! $this->isPaid()
            && $this->due_date
            && $this->due_date->isPast()
            && $this->outstandingAmount() > 0;
    }
}

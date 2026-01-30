<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory;

    protected $table = 'sales';

    protected $fillable = [
        'branch_id',
        'pos_shift_id',
        'customer_id',
        'sale_number',
        'status',
        'order_type',
        'currency',
        'subtotal_cents',
        'discount_total_cents',
        'global_discount_cents',
        'global_discount_type',
        'global_discount_value',
        'is_credit',
        'credit_invoice_id',
        'pos_date',
        'tax_total_cents',
        'total_cents',
        'paid_total_cents',
        'due_total_cents',
        'notes',
        'reference',
        'pos_reference',
        'held_at',
        'held_by',
        'kot_printed_at',
        'kot_printed_by',
        'created_by',
        'updated_by',
        'closed_at',
        'closed_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'subtotal_cents' => 'integer',
        'discount_total_cents' => 'integer',
        'global_discount_cents' => 'integer',
        'global_discount_value' => 'integer',
        'is_credit' => 'boolean',
        'credit_invoice_id' => 'integer',
        'pos_date' => 'date',
        'tax_total_cents' => 'integer',
        'total_cents' => 'integer',
        'paid_total_cents' => 'integer',
        'due_total_cents' => 'integer',
        'held_at' => 'datetime',
        'kot_printed_at' => 'datetime',
        'closed_at' => 'datetime',
        'voided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'pos_shift_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function paymentAllocations(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable')
            ->whereNull('voided_at');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'open'], true);
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isHeld(): bool
    {
        return $this->held_at !== null;
    }
}


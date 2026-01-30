<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class ArInvoice extends Model
{
    use HasFactory;

    protected $table = 'ar_invoices';

    protected $fillable = [
        'branch_id',
        'customer_id',
        'source_sale_id',
        'type',
        'invoice_number',
        'status',
        'payment_type',
        'payment_term_id',
        'payment_term_days',
        'sales_person_id',
        'lpo_reference',
        'issue_date',
        'due_date',
        'currency',
        'subtotal_cents',
        'discount_total_cents',
        'invoice_discount_type',
        'invoice_discount_value',
        'invoice_discount_cents',
        'tax_total_cents',
        'total_cents',
        'paid_total_cents',
        'balance_cents',
        'notes',
        'created_by',
        'updated_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'payment_term_days' => 'integer',
        'payment_term_id' => 'integer',
        'subtotal_cents' => 'integer',
        'discount_total_cents' => 'integer',
        'invoice_discount_value' => 'integer',
        'invoice_discount_cents' => 'integer',
        'tax_total_cents' => 'integer',
        'total_cents' => 'integer',
        'paid_total_cents' => 'integer',
        'balance_cents' => 'integer',
        'voided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (ArInvoice $invoice) {
            if ($invoice->getOriginal('status') === 'draft') {
                return;
            }

            $dirty = array_keys($invoice->getDirty());
            if ($dirty === []) {
                return;
            }

            // Issued/paid invoices are immutable except status/balance/void fields.
            $allowed = [
                'status',
                'paid_total_cents',
                'balance_cents',
                'voided_at',
                'voided_by',
                'void_reason',
                'updated_at',
            ];

            foreach ($dirty as $field) {
                if (! in_array($field, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'invoice' => __('Issued invoices are immutable. Use a credit note for adjustments.'),
                    ]);
                }
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'source_sale_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ArInvoiceItem::class, 'invoice_id');
    }

    public function paymentAllocations(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable')
            ->whereNull('voided_at');
    }

    public function isDraft(): bool { return $this->status === 'draft'; }
    public function isIssued(): bool { return $this->status === 'issued'; }
    public function isPartiallyPaid(): bool { return $this->status === 'partially_paid'; }
    public function isPaid(): bool { return $this->status === 'paid'; }
    public function isVoided(): bool { return $this->status === 'voided'; }
    public function isCreditNote(): bool { return $this->type === 'credit_note'; }
}


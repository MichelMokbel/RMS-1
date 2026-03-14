<?php

namespace App\Models;

use App\Support\AP\DocumentTypeMap;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Supplier;
use App\Models\ExpenseCategory;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ApInvoice extends Model
{
    use HasFactory;

    protected $table = 'ap_invoices';

    protected $fillable = [
        'company_id',
        'branch_id',
        'department_id',
        'job_id',
        'period_id',
        'supplier_id',
        'purchase_order_id',
        'category_id',
        'is_expense',
        'document_type',
        'currency_code',
        'source_document_type',
        'source_document_id',
        'recurring_template_id',
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
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'department_id' => 'integer',
        'job_id' => 'integer',
        'period_id' => 'integer',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
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
        return $this->hasMany(ApPaymentAllocation::class, 'invoice_id')
            ->whereNull('voided_at');
    }

    public function allAllocations(): HasMany
    {
        return $this->hasMany(ApPaymentAllocation::class, 'invoice_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ApInvoiceAttachment::class, 'invoice_id');
    }

    public function expenseProfile(): BelongsTo
    {
        return $this->belongsTo(ExpenseProfile::class, 'id', 'invoice_id');
    }

    public function expenseEvents(): HasMany
    {
        return $this->hasMany(ExpenseEvent::class, 'invoice_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
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
        return $this->isDraft()
            && $this->supplier_id
            && $this->items()->count() > 0
            && ($this->company_id !== null);
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

    public function documentTypeLabel(): string
    {
        return DocumentTypeMap::label($this->document_type);
    }

    public function approvalStatusLabel(): string
    {
        return DocumentTypeMap::approvalStatus($this);
    }

    public function workflowStateLabel(): string
    {
        return DocumentTypeMap::workflowState($this);
    }

    public function paymentStateLabel(): string
    {
        return DocumentTypeMap::paymentState($this);
    }
}

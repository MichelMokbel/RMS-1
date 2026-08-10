<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quotation extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'company_id', 'branch_id', 'customer_id', 'template_version_id',
        'duplicated_from_quotation_id', 'quotation_number', 'number', 'status', 'current_revision',
        'issue_date', 'valid_until', 'currency', 'recipient_name',
        'recipient_contact_name', 'recipient_email', 'recipient_phone', 'recipient_address',
        'page_settings', 'styles', 'table_columns', 'blocks', 'gross_subtotal_cents',
        'line_discount_total_cents', 'subtotal_cents', 'quotation_discount_type',
        'quotation_discount_value', 'quotation_discount_cents', 'discount_total_cents',
        'total_cents', 'total_minor', 'converted_invoice_id', 'converted_at', 'created_by', 'updated_by',
    ];

    protected $appends = ['number', 'total_minor'];

    protected $casts = [
        'company_id' => 'integer', 'branch_id' => 'integer', 'customer_id' => 'integer',
        'template_version_id' => 'integer', 'duplicated_from_quotation_id' => 'integer',
        'current_revision' => 'integer', 'issue_date' => 'date', 'valid_until' => 'date',
        'page_settings' => 'array', 'styles' => 'array', 'table_columns' => 'array', 'blocks' => 'array',
        'gross_subtotal_cents' => 'integer', 'line_discount_total_cents' => 'integer',
        'subtotal_cents' => 'integer', 'quotation_discount_value' => 'integer',
        'quotation_discount_cents' => 'integer', 'discount_total_cents' => 'integer',
        'total_cents' => 'integer', 'converted_invoice_id' => 'integer', 'converted_at' => 'datetime',
    ];

    public function getNumberAttribute(): ?string
    {
        return $this->quotation_number;
    }

    public function setNumberAttribute(?string $value): void
    {
        $this->attributes['quotation_number'] = $value;
    }

    public function getTotalMinorAttribute(): int
    {
        return (int) $this->total_cents;
    }

    public function setTotalMinorAttribute(int $value): void
    {
        $this->attributes['total_cents'] = $value;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplateVersion::class, 'template_version_id');
    }

    public function duplicatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicated_from_quotation_id');
    }

    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class, 'converted_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(QuotationVersion::class)->orderBy('revision');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(QuotationStatusEvent::class)->orderBy('created_at');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(\App\Services\Security\BranchAccessService::class)->applyBranchScope($query, $user);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isConverted(): bool
    {
        return $this->converted_invoice_id !== null;
    }
}

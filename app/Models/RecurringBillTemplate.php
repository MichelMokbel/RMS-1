<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringBillTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'branch_id',
        'supplier_id',
        'department_id',
        'job_id',
        'name',
        'document_type',
        'frequency',
        'default_amount',
        'due_day_offset',
        'start_date',
        'end_date',
        'next_run_date',
        'last_run_date',
        'is_active',
        'line_template',
        'notes',
    ];

    protected $casts = [
        'default_amount' => 'decimal:2',
        'due_day_offset' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'next_run_date' => 'date',
        'last_run_date' => 'date',
        'is_active' => 'boolean',
        'line_template' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecurringBillTemplateLine::class, 'recurring_bill_template_id')->orderBy('sort_order');
    }

    public function generatedInvoices(): HasMany
    {
        return $this->hasMany(ApInvoice::class, 'recurring_template_id')->latest('invoice_date');
    }
}

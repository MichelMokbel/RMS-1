<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceSetting extends Model
{
    use HasFactory;

    protected $table = 'finance_settings';

    protected $fillable = [
        'lock_date',
        'default_company_id',
        'default_bank_account_id',
        'po_quantity_tolerance_percent',
        'po_price_tolerance_percent',
        'purchase_price_variance_account_id',
        'updated_by',
        'ap_report_enabled',
        'ap_report_email',
        'ap_report_company_id',
    ];

    protected $casts = [
        'lock_date' => 'date',
        'ap_report_enabled' => 'boolean',
        'ap_report_company_id' => 'integer',
        'default_company_id' => 'integer',
        'default_bank_account_id' => 'integer',
        'po_quantity_tolerance_percent' => 'decimal:3',
        'po_price_tolerance_percent' => 'decimal:3',
        'purchase_price_variance_account_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function defaultCompany(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'default_company_id');
    }

    public function defaultBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'default_bank_account_id');
    }

    public function purchasePriceVarianceAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'purchase_price_variance_account_id');
    }
}

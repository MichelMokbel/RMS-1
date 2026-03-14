<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'ledger_account_id',
        'branch_id',
        'name',
        'code',
        'account_type',
        'bank_name',
        'account_number_last4',
        'currency_code',
        'is_default',
        'is_active',
        'opening_balance',
        'opening_balance_date',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'ledger_account_id' => 'integer',
        'branch_id' => 'integer',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'opening_balance' => 'decimal:2',
        'opening_balance_date' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'bank_account_id');
    }

    public function statementImports(): HasMany
    {
        return $this->hasMany(BankStatementImport::class, 'bank_account_id');
    }

    public function reconciliationRuns(): HasMany
    {
        return $this->hasMany(BankReconciliationRun::class, 'bank_account_id');
    }
}

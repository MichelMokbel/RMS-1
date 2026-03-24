<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'bank_account_id',
        'period_id',
        'reconciliation_run_id',
        'matched_bank_transaction_id',
        'transaction_type',
        'transaction_date',
        'amount',
        'direction',
        'status',
        'is_cleared',
        'cleared_date',
        'reference',
        'memo',
        'source_type',
        'source_id',
        'statement_import_id',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
        'is_cleared' => 'boolean',
        'cleared_date' => 'date',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function statementImport(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'statement_import_id');
    }

    public function reconciliationRun(): BelongsTo
    {
        return $this->belongsTo(BankReconciliationRun::class, 'reconciliation_run_id');
    }

    public function matchedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'matched_bank_transaction_id');
    }

    public function matchingTransactions(): HasMany
    {
        return $this->hasMany(self::class, 'matched_bank_transaction_id');
    }
}

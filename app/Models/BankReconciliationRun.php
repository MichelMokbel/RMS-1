<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankReconciliationRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_account_id',
        'company_id',
        'period_id',
        'statement_import_id',
        'statement_date',
        'statement_ending_balance',
        'book_ending_balance',
        'variance_amount',
        'status',
        'completed_at',
        'completed_by',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'statement_ending_balance' => 'decimal:2',
        'book_ending_balance' => 'decimal:2',
        'variance_amount' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function statementImport(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'statement_import_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'reconciliation_run_id');
    }
}

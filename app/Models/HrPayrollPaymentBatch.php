<?php

namespace App\Models;

use App\Enums\HR\PayrollPaymentBatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrPayrollPaymentBatch extends Model
{
    protected $attributes = [
        'currency' => 'QAR',
        'total_minor' => 0,
        'item_count' => 0,
        'status' => 'draft',
    ];

    protected $fillable = [
        'company_id', 'payroll_run_id', 'bank_account_id', 'batch_number', 'payment_date',
        'currency', 'total_minor', 'item_count', 'status', 'reference', 'journal_entry_id',
        'reversal_journal_entry_id', 'bank_transaction_id', 'created_by', 'processed_at',
        'processed_by', 'reversed_at', 'reversed_by',
    ];

    protected $casts = [
        'company_id' => 'integer', 'payroll_run_id' => 'integer', 'bank_account_id' => 'integer',
        'payment_date' => 'date', 'total_minor' => 'integer', 'item_count' => 'integer',
        'status' => PayrollPaymentBatchStatus::class, 'processed_at' => 'datetime', 'reversed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $batch): void {
            $originalStatus = $batch->getRawOriginal('status');
            if ($originalStatus === 'reversed') {
                throw new \LogicException('Processed payroll payment batches are immutable.');
            }
            if (in_array($originalStatus, ['processed', 'partially_failed'], true)) {
                $allowed = ['status', 'reversed_at', 'reversed_by', 'reversal_journal_entry_id', 'updated_at'];
                if (array_diff(array_keys($batch->getDirty()), $allowed)) {
                    throw new \LogicException('Processed payment details are immutable; only reversal fields may change.');
                }
            }
        });
        static::deleting(function (self $batch): void {
            if ($batch->getRawOriginal('status') !== 'draft' || $batch->items()->exists()) {
                throw new \LogicException('Only an empty draft payment batch may be deleted.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(HrPayrollRun::class, 'payroll_run_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(HrPayrollPaymentBatchItem::class, 'payment_batch_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }
}

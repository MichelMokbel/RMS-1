<?php

namespace App\Models;

use App\Enums\HR\PayrollOrigin;
use App\Enums\HR\PayrollRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrPayrollRun extends Model
{
    protected $attributes = [
        'currency' => 'QAR',
        'origin' => 'native',
        'status' => 'draft',
        'is_postable' => true,
        'proration_divisor' => 30,
        'lock_version' => 0,
    ];

    protected $fillable = [
        'company_id', 'run_number', 'period_id', 'pay_period_start', 'pay_period_end',
        'scheduled_payment_date', 'currency', 'origin', 'status', 'is_postable',
        'proration_divisor', 'description', 'prepared_by', 'calculated_at', 'calculated_by',
        'approved_at', 'approved_by', 'posted_at', 'posted_by', 'paid_at', 'paid_by',
        'rejected_at', 'rejected_by', 'reversed_at', 'reversed_by', 'journal_entry_id',
        'reversal_journal_entry_id', 'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'period_id' => 'integer', 'pay_period_start' => 'date',
            'pay_period_end' => 'date', 'scheduled_payment_date' => 'date', 'origin' => PayrollOrigin::class,
            'status' => PayrollRunStatus::class, 'is_postable' => 'boolean', 'proration_divisor' => 'decimal:2',
            'calculated_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime',
            'paid_at' => 'datetime', 'rejected_at' => 'datetime', 'reversed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $run): void {
            $origin = $run->origin instanceof PayrollOrigin ? $run->origin->value : $run->origin;
            if ($origin === PayrollOrigin::Migrated->value) {
                $run->is_postable = false;
            }

            if (! $run->exists) {
                return;
            }

            if ($run->getRawOriginal('origin') === PayrollOrigin::Migrated->value && $run->isDirty()) {
                throw new \LogicException('Migrated payroll runs are read-only.');
            }

            $originalStatus = $run->getRawOriginal('status');
            if ($originalStatus === 'reversed' && $run->isDirty()) {
                throw new \LogicException('Completed payroll runs are immutable.');
            }

            if (in_array($originalStatus, ['posted', 'paid'], true)) {
                $allowed = $originalStatus === 'posted' ? [
                    'status', 'paid_at', 'paid_by', 'reversed_at', 'reversed_by',
                    'reversal_journal_entry_id', 'lock_version', 'updated_at',
                ] : [
                    'status', 'reversed_at', 'reversed_by', 'reversal_journal_entry_id',
                    'lock_version', 'updated_at',
                ];
                if (array_diff(array_keys($run->getDirty()), $allowed)) {
                    throw new \LogicException('Completed payroll details are immutable; only an allowed payment or reversal transition may be recorded.');
                }
            }
        });

        static::deleting(function (self $run): void {
            if ($run->getRawOriginal('origin') === PayrollOrigin::Migrated->value
                || $run->getRawOriginal('status') !== 'draft'
                || $run->results()->exists()) {
                throw new \LogicException('Only an empty draft payroll run may be deleted.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(HrPayrollResult::class, 'payroll_run_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(HrPayrollAdjustment::class, 'payroll_run_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(HrPayrollStatusEvent::class, 'payroll_run_id');
    }

    public function paymentBatches(): HasMany
    {
        return $this->hasMany(HrPayrollPaymentBatch::class, 'payroll_run_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class JournalEntryLine extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (JournalEntryLine $line) {
            if ($line->journalEntry?->status === 'draft') {
                return;
            }

            throw ValidationException::withMessages([
                'journal' => __('Posted journal entries are immutable.'),
            ]);
        });

        static::deleting(function (JournalEntryLine $line) {
            if ($line->journalEntry?->status === 'draft') {
                return;
            }

            throw ValidationException::withMessages([
                'journal' => __('Posted journal entries are immutable.'),
            ]);
        });
    }

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'department_id',
        'job_id',
        'branch_id',
        'debit',
        'credit',
        'memo',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class JournalEntry extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $journal) {
            if ($journal->getOriginal('status') === 'draft') {
                return;
            }

            throw ValidationException::withMessages([
                'journal' => __('Posted journal entries are immutable.'),
            ]);
        });

        static::deleting(function (JournalEntry $journal) {
            if ($journal->status === 'draft') {
                return;
            }

            throw ValidationException::withMessages([
                'journal' => __('Posted journal entries are immutable.'),
            ]);
        });
    }

    protected $fillable = [
        'company_id',
        'period_id',
        'entry_number',
        'entry_type',
        'entry_date',
        'status',
        'source_type',
        'source_id',
        'memo',
        'posted_at',
        'posted_by',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'journal_entry_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}

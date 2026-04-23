<?php

namespace App\Models;

use App\Services\Sequences\DocumentSequenceService;
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
        static::creating(function (JournalEntry $journal) {
            if (filled($journal->entry_number)) {
                return;
            }

            $entryDate = optional($journal->entry_date)?->toDateString()
                ?? (is_string($journal->entry_date) ? $journal->entry_date : now()->toDateString());
            $year = date('Y', strtotime($entryDate));
            $companyId = max((int) ($journal->company_id ?? 0), 1);
            $sequence = app(DocumentSequenceService::class)->next('journal_entry', $companyId, $year);

            $journal->entry_number = sprintf('JRN-%s-%04d', $year, $sequence);
        });

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

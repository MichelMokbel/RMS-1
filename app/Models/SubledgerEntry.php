<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class SubledgerEntry extends Model
{
    use HasFactory;

    protected $table = 'subledger_entries';

    protected $fillable = [
        'source_type',
        'source_id',
        'event',
        'entry_date',
        'description',
        'branch_id',
        'status',
        'posted_at',
        'posted_by',
        'voided_at',
        'voided_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw ValidationException::withMessages(['ledger' => __('Posted ledger entries are immutable.')]);
        });

        static::deleting(function () {
            throw ValidationException::withMessages(['ledger' => __('Posted ledger entries are immutable.')]);
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SubledgerLine::class, 'entry_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}

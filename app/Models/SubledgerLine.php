<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class SubledgerLine extends Model
{
    use HasFactory;

    protected $table = 'subledger_lines';

    protected $fillable = [
        'entry_id',
        'account_id',
        'debit',
        'credit',
        'memo',
    ];

    protected $casts = [
        'debit' => 'decimal:4',
        'credit' => 'decimal:4',
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

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SubledgerEntry::class, 'entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }
}

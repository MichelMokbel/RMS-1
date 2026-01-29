<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PettyCashIssue extends Model
{
    use HasFactory;

    protected $table = 'petty_cash_issues';
    public $timestamps = false;
    public const CREATED_AT = 'created_at';

    protected $fillable = [
        'wallet_id',
        'issue_date',
        'amount',
        'method',
        'reference',
        'issued_by',
        'voided_at',
        'voided_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (PettyCashIssue $issue) {
            if ($issue->getDirty() === []) {
                return;
            }

            $allowed = ['voided_at', 'voided_by'];
            foreach (array_keys($issue->getDirty()) as $field) {
                if (! in_array($field, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'issue' => __('Petty cash issues are immutable after posting.'),
                    ]);
                }
            }
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(PettyCashWallet::class, 'wallet_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}

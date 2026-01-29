<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PettyCashReconciliation extends Model
{
    use HasFactory;

    protected $table = 'petty_cash_reconciliations';
    public $timestamps = false;

    protected $fillable = [
        'wallet_id',
        'period_start',
        'period_end',
        'expected_balance',
        'counted_balance',
        'variance',
        'note',
        'reconciled_by',
        'reconciled_at',
        'voided_at',
        'voided_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'expected_balance' => 'decimal:2',
        'counted_balance' => 'decimal:2',
        'variance' => 'decimal:2',
        'reconciled_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (PettyCashReconciliation $recon) {
            if ($recon->getDirty() === []) {
                return;
            }

            $allowed = ['voided_at', 'voided_by'];
            foreach (array_keys($recon->getDirty()) as $field) {
                if (! in_array($field, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'reconciliation' => __('Petty cash reconciliations are immutable after posting.'),
                    ]);
                }
            }
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(PettyCashWallet::class, 'wallet_id');
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'expected_balance' => 'decimal:2',
        'counted_balance' => 'decimal:2',
        'variance' => 'decimal:2',
        'reconciled_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(PettyCashWallet::class, 'wallet_id');
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashWallet extends Model
{
    use HasFactory;

    protected $table = 'petty_cash_wallets';
    public $timestamps = false;
    public const CREATED_AT = 'created_at';

    protected $fillable = [
        'driver_id',
        'driver_name',
        'target_float',
        'balance',
        'active',
        'created_by',
    ];

    protected $casts = [
        'target_float' => 'decimal:2',
        'balance' => 'decimal:2',
        'active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function issues(): HasMany
    {
        return $this->hasMany(PettyCashIssue::class, 'wallet_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(PettyCashExpense::class, 'wallet_id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(PettyCashReconciliation::class, 'wallet_id');
    }

    public function isActive(): bool
    {
        return (bool) $this->active;
    }

    public function canTransact(): bool
    {
        return $this->isActive();
    }
}

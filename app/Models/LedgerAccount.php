<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    use HasFactory;

    protected $table = 'ledger_accounts';

    protected $fillable = [
        'code',
        'name',
        'type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function subledgerLines(): HasMany
    {
        return $this->hasMany(SubledgerLine::class, 'account_id');
    }

    public function glBatchLines(): HasMany
    {
        return $this->hasMany(GlBatchLine::class, 'account_id');
    }
}

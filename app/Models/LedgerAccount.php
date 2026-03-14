<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    use HasFactory;

    protected $table = 'ledger_accounts';

    protected $fillable = [
        'company_id',
        'code',
        'parent_account_id',
        'name',
        'type',
        'account_class',
        'detail_type',
        'default_tax_code',
        'is_active',
        'allow_direct_posting',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'parent_account_id' => 'integer',
        'is_active' => 'boolean',
        'allow_direct_posting' => 'boolean',
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(AccountingAccountMapping::class, 'ledger_account_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }
}

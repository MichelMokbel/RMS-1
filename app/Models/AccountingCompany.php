<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingCompany extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'base_currency',
        'is_active',
        'is_default',
        'parent_company_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class, 'company_id');
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class, 'company_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'company_id');
    }
}

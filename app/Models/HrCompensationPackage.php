<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrCompensationPackage extends Model
{
    protected $attributes = [
        'currency' => 'QAR',
        'pay_frequency' => 'monthly',
        'proration_divisor' => 30,
        'is_active' => true,
    ];

    protected $fillable = [
        'company_id', 'employee_id', 'effective_from', 'effective_to', 'currency', 'pay_frequency',
        'proration_divisor', 'bank_name', 'beneficiary_name', 'bank_account_number', 'iban',
        'swift_code', 'is_active', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'employee_id' => 'integer', 'effective_from' => 'date',
            'effective_to' => 'date', 'proration_divisor' => 'decimal:2', 'is_active' => 'boolean',
            'beneficiary_name' => 'encrypted', 'bank_account_number' => 'encrypted',
            'iban' => 'encrypted', 'swift_code' => 'encrypted',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(HrCompensationComponent::class, 'compensation_package_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

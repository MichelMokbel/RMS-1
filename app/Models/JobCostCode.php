<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobCostCode extends Model
{
    use HasFactory;

    protected $table = 'accounting_job_cost_codes';

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'default_account_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function defaultAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'default_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(JobTransaction::class, 'job_cost_code_id');
    }
}

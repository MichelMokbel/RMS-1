<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Job extends Model
{
    use HasFactory;

    protected $table = 'accounting_jobs';

    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'code',
        'status',
        'start_date',
        'end_date',
        'estimated_revenue',
        'estimated_cost',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'estimated_revenue' => 'decimal:2',
        'estimated_cost' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function phases(): HasMany
    {
        return $this->hasMany(JobPhase::class, 'job_id');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(JobBudget::class, 'job_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(JobTransaction::class, 'job_id');
    }
}

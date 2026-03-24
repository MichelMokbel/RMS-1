<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobBudget extends Model
{
    use HasFactory;

    protected $table = 'accounting_job_budgets';

    protected $fillable = [
        'job_id',
        'job_phase_id',
        'job_cost_code_id',
        'budget_amount',
    ];

    protected $casts = [
        'budget_amount' => 'decimal:2',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(JobPhase::class, 'job_phase_id');
    }

    public function costCode(): BelongsTo
    {
        return $this->belongsTo(JobCostCode::class, 'job_cost_code_id');
    }
}

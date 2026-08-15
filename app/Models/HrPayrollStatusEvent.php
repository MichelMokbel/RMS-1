<?php

namespace App\Models;

use App\Enums\HR\PayrollRunStatus;
use App\Models\Concerns\HrAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrPayrollStatusEvent extends Model
{
    use HrAppendOnly;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'payroll_run_id', 'from_status', 'to_status',
        'actor_id', 'reason', 'metadata', 'created_at',
    ];

    protected $casts = [
        'company_id' => 'integer', 'payroll_run_id' => 'integer',
        'from_status' => PayrollRunStatus::class, 'to_status' => PayrollRunStatus::class,
        'actor_id' => 'integer', 'metadata' => 'array', 'created_at' => 'datetime',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(HrPayrollRun::class, 'payroll_run_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

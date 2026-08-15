<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class HrEmployeeAssignment extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'branch_id', 'department_id', 'manager_id', 'job_title',
        'employment_type', 'effective_from', 'effective_to', 'is_primary', 'notes', 'created_by',
    ];

    protected $casts = [
        'company_id' => 'integer', 'employee_id' => 'integer', 'branch_id' => 'integer',
        'department_id' => 'integer', 'manager_id' => 'integer', 'effective_from' => 'date',
        'effective_to' => 'date', 'is_primary' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $assignment): void {
            $companyId = (int) $assignment->company_id;
            $employee = HrEmployee::query()->find($assignment->employee_id);

            if (! $employee || (int) $employee->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'employee_id' => __('The employee must belong to the assignment company.'),
                ]);
            }

            if ($assignment->branch_id && ! Branch::query()
                ->whereKey($assignment->branch_id)
                ->where('company_id', $companyId)
                ->exists()) {
                throw ValidationException::withMessages([
                    'branch_id' => __('The branch must belong to the assignment company.'),
                ]);
            }

            if ($assignment->department_id && ! Department::query()
                ->whereKey($assignment->department_id)
                ->where('company_id', $companyId)
                ->exists()) {
                throw ValidationException::withMessages([
                    'department_id' => __('The department must belong to the assignment company.'),
                ]);
            }

            if ($assignment->manager_id && ($assignment->manager_id === $assignment->employee_id
                || ! HrEmployee::query()->whereKey($assignment->manager_id)->where('company_id', $companyId)->exists())) {
                throw ValidationException::withMessages([
                    'manager_id' => __('The manager must be another employee in the assignment company.'),
                ]);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'manager_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

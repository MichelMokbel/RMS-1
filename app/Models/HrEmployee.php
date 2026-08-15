<?php

namespace App\Models;

use App\Enums\HR\EmployeeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class HrEmployee extends Model
{
    use HasFactory;

    protected $attributes = [
        'employment_type' => 'full_time',
        'employment_status' => 'onboarding',
    ];

    protected $fillable = [
        'company_id', 'employee_number', 'user_id', 'manager_id', 'current_branch_id',
        'current_department_id', 'legal_first_name', 'legal_middle_name', 'legal_last_name',
        'display_name', 'preferred_name', 'work_email', 'personal_email', 'work_phone',
        'personal_phone', 'date_of_birth', 'nationality', 'gender', 'qid_number',
        'passport_number', 'address', 'emergency_contact', 'job_title', 'employment_type',
        'employment_status', 'hire_date', 'probation_end_date', 'notice_date', 'exit_date',
        'exit_reason', 'metadata', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'user_id' => 'integer', 'manager_id' => 'integer',
            'current_branch_id' => 'integer', 'current_department_id' => 'integer',
            'date_of_birth' => 'date', 'hire_date' => 'date', 'probation_end_date' => 'date',
            'notice_date' => 'date', 'exit_date' => 'date',
            'qid_number' => 'encrypted', 'passport_number' => 'encrypted',
            'address' => 'array', 'emergency_contact' => 'array', 'metadata' => 'array',
            'employment_status' => EmployeeStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $employee): void {
            $companyId = (int) $employee->company_id;

            if ($employee->current_branch_id && ! Branch::query()
                ->whereKey($employee->current_branch_id)
                ->where('company_id', $companyId)
                ->exists()) {
                throw ValidationException::withMessages([
                    'current_branch_id' => __('The branch must belong to the employee company.'),
                ]);
            }

            if ($employee->current_department_id && ! Department::query()
                ->whereKey($employee->current_department_id)
                ->where('company_id', $companyId)
                ->exists()) {
                throw ValidationException::withMessages([
                    'current_department_id' => __('The department must belong to the employee company.'),
                ]);
            }

            if ($employee->manager_id) {
                $sameCompanyManager = self::query()
                    ->whereKey($employee->manager_id)
                    ->where('company_id', $companyId)
                    ->when($employee->exists, fn ($query) => $query->where('id', '!=', $employee->getKey()))
                    ->exists();

                if (! $sameCompanyManager) {
                    throw ValidationException::withMessages([
                        'manager_id' => __('The manager must be another employee in the same company.'),
                    ]);
                }
            }
        });

        static::deleting(function (self $employee): void {
            $hasHistory = $employee->assignments()->exists()
                || $employee->compensationPackages()->exists()
                || $employee->documents()->exists()
                || $employee->leaveRequests()->exists()
                || $employee->payrollResults()->exists();

            if ($hasHistory) {
                throw new \LogicException('Employees with HR history cannot be deleted; archive the employee instead.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function currentBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'current_branch_id');
    }

    public function currentDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'current_department_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(HrEmployeeAssignment::class, 'employee_id');
    }

    public function compensationPackages(): HasMany
    {
        return $this->hasMany(HrCompensationPackage::class, 'employee_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(HrDocument::class, 'employee_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(HrLeaveRequest::class, 'employee_id');
    }

    public function leaveLedgerEntries(): HasMany
    {
        return $this->hasMany(HrLeaveLedgerEntry::class, 'employee_id');
    }

    public function payrollResults(): HasMany
    {
        return $this->hasMany(HrPayrollResult::class, 'employee_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(HrAlert::class, 'employee_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrPayrollResult extends Model
{
    protected $fillable = [
        'company_id', 'payroll_run_id', 'employee_id', 'assignment_id', 'compensation_package_id',
        'branch_id', 'department_id', 'currency', 'basic_minor', 'gross_minor', 'earnings_minor',
        'deductions_minor', 'net_minor', 'calendar_days', 'worked_days', 'unpaid_leave_days',
        'proration_divisor', 'snapshot', 'payslip_document_id', 'is_postable',
    ];

    protected $casts = [
        'company_id' => 'integer', 'payroll_run_id' => 'integer', 'employee_id' => 'integer',
        'assignment_id' => 'integer', 'compensation_package_id' => 'integer', 'branch_id' => 'integer',
        'department_id' => 'integer', 'basic_minor' => 'integer', 'gross_minor' => 'integer',
        'earnings_minor' => 'integer', 'deductions_minor' => 'integer', 'net_minor' => 'integer',
        'calendar_days' => 'decimal:2', 'worked_days' => 'decimal:2', 'unpaid_leave_days' => 'decimal:2',
        'proration_divisor' => 'decimal:2', 'snapshot' => 'array', 'is_postable' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $result): void {
            $origin = HrPayrollRun::query()->whereKey($result->payroll_run_id)->value('origin');
            $origin = is_object($origin) && isset($origin->value) ? $origin->value : $origin;
            if ($origin === 'migrated') {
                $result->is_postable = false;
            }
        });

        $guard = function (self $result): void {
            $run = $result->payrollRun()->first(['status', 'origin']);
            if ($run?->getRawOriginal('origin') === 'migrated' || $run?->getRawOriginal('status') !== 'draft') {
                throw new \LogicException('Payroll results are immutable outside a draft run.');
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(HrPayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeAssignment::class, 'assignment_id');
    }

    public function compensationPackage(): BelongsTo
    {
        return $this->belongsTo(HrCompensationPackage::class, 'compensation_package_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function payslipDocument(): BelongsTo
    {
        return $this->belongsTo(HrDocument::class, 'payslip_document_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(HrPayrollResultComponent::class, 'payroll_result_id');
    }

    public function paymentItems(): HasMany
    {
        return $this->hasMany(HrPayrollPaymentBatchItem::class, 'payroll_result_id');
    }
}

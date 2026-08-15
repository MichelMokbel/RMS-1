<?php

namespace App\Models;

use App\Enums\HR\PayrollAdjustmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrPayrollAdjustment extends Model
{
    protected $fillable = [
        'company_id', 'payroll_run_id', 'employee_id', 'type', 'code', 'description',
        'amount_minor', 'source_type', 'source_id', 'idempotency_key', 'notes',
        'created_by', 'approved_at', 'approved_by',
    ];

    protected $casts = [
        'company_id' => 'integer', 'payroll_run_id' => 'integer', 'employee_id' => 'integer',
        'type' => PayrollAdjustmentType::class, 'amount_minor' => 'integer', 'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $adjustment): void {
            $runStatus = $adjustment->payrollRun()->value('status');
            $status = is_object($runStatus) ? $runStatus->value : (string) $runStatus;
            if ($status === 'draft') {
                return;
            }

            $approvalFields = ['approved_at', 'approved_by', 'updated_at'];
            if ($status === 'calculated'
                && $adjustment->getRawOriginal('approved_at') === null
                && array_diff(array_keys($adjustment->getDirty()), $approvalFields) === []) {
                return;
            }

            throw new \LogicException('Payroll adjustments are immutable outside a draft run.');
        });

        static::deleting(function (self $adjustment): void {
            $runStatus = $adjustment->payrollRun()->value('status');
            $status = is_object($runStatus) ? $runStatus->value : (string) $runStatus;
            if ($status !== 'draft') {
                throw new \LogicException('Payroll adjustments are immutable outside a draft run.');
            }
        });
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

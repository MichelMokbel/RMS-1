<?php

namespace App\Models;

use App\Models\Concerns\HrAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrPayrollPaymentBatchItem extends Model
{
    use HrAppendOnly;

    protected $fillable = [
        'payment_batch_id', 'payroll_result_id', 'employee_id', 'amount_minor',
        'beneficiary_name', 'bank_account_number', 'iban', 'payment_reference', 'status', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'payment_batch_id' => 'integer', 'payroll_result_id' => 'integer',
            'employee_id' => 'integer', 'amount_minor' => 'integer',
            'beneficiary_name' => 'encrypted', 'bank_account_number' => 'encrypted', 'iban' => 'encrypted',
        ];
    }

    public function paymentBatch(): BelongsTo
    {
        return $this->belongsTo(HrPayrollPaymentBatch::class, 'payment_batch_id');
    }

    public function payrollResult(): BelongsTo
    {
        return $this->belongsTo(HrPayrollResult::class, 'payroll_result_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}

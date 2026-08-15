<?php

namespace App\Models;

use App\Models\Concerns\HrAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrPayrollResultComponent extends Model
{
    use HrAppendOnly;

    protected $fillable = [
        'payroll_result_id', 'code', 'name', 'category', 'amount_minor', 'source_type',
        'source_id', 'is_taxable', 'snapshot',
    ];

    protected $casts = [
        'payroll_result_id' => 'integer', 'amount_minor' => 'integer',
        'is_taxable' => 'boolean', 'snapshot' => 'array',
    ];

    public function payrollResult(): BelongsTo
    {
        return $this->belongsTo(HrPayrollResult::class, 'payroll_result_id');
    }
}

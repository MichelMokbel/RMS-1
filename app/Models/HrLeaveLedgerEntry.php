<?php

namespace App\Models;

use App\Enums\HR\LeaveLedgerEntryType;
use App\Models\Concerns\HrAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrLeaveLedgerEntry extends Model
{
    use HrAppendOnly;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'employee_id', 'leave_type_id', 'policy_id', 'leave_request_id',
        'entry_type', 'days', 'effective_date', 'source_type', 'source_id', 'idempotency_key',
        'balance_after_days', 'notes', 'created_by', 'created_at',
    ];

    protected $casts = [
        'company_id' => 'integer', 'employee_id' => 'integer', 'leave_type_id' => 'integer',
        'policy_id' => 'integer', 'leave_request_id' => 'integer', 'entry_type' => LeaveLedgerEntryType::class,
        'days' => 'decimal:2', 'effective_date' => 'date', 'balance_after_days' => 'decimal:2',
        'created_by' => 'integer', 'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(HrLeaveType::class, 'leave_type_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(HrLeavePolicy::class, 'policy_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(HrLeaveRequest::class, 'leave_request_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

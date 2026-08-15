<?php

namespace App\Models;

use App\Enums\HR\LeaveRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrLeaveRequest extends Model
{
    protected $attributes = [
        'start_portion' => 'full',
        'end_portion' => 'full',
        'status' => 'draft',
    ];

    protected $fillable = [
        'company_id', 'employee_id', 'leave_type_id', 'policy_id', 'manager_id', 'start_date',
        'end_date', 'start_portion', 'end_portion', 'requested_days', 'status', 'reason',
        'attachment_document_id', 'submitted_at', 'decided_at', 'decided_by', 'cancelled_at',
        'cancelled_by', 'decision_reason', 'created_by',
    ];

    protected $casts = [
        'company_id' => 'integer', 'employee_id' => 'integer', 'leave_type_id' => 'integer',
        'policy_id' => 'integer', 'manager_id' => 'integer', 'start_date' => 'date', 'end_date' => 'date',
        'requested_days' => 'decimal:2', 'status' => LeaveRequestStatus::class,
        'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'cancelled_at' => 'datetime',
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

    public function manager(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'manager_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(HrDocument::class, 'attachment_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(HrLeaveRequestEvent::class, 'leave_request_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(HrLeaveLedgerEntry::class, 'leave_request_id');
    }
}

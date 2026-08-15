<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrLeavePolicy extends Model
{
    protected $fillable = [
        'company_id', 'leave_type_id', 'name', 'effective_from', 'effective_to',
        'entitlement_days', 'accrual_rate_days', 'accrual_frequency', 'carryover_limit_days',
        'max_balance_days', 'waiting_period_days', 'allow_negative', 'requires_attachment',
        'counts_calendar_days', 'is_active', 'rules',
    ];

    protected $casts = [
        'company_id' => 'integer', 'leave_type_id' => 'integer', 'effective_from' => 'date',
        'effective_to' => 'date', 'entitlement_days' => 'decimal:2', 'accrual_rate_days' => 'decimal:4',
        'carryover_limit_days' => 'decimal:2', 'max_balance_days' => 'decimal:2',
        'waiting_period_days' => 'integer', 'allow_negative' => 'boolean',
        'requires_attachment' => 'boolean', 'counts_calendar_days' => 'boolean',
        'is_active' => 'boolean', 'rules' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(HrLeaveType::class, 'leave_type_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(HrLeaveRequest::class, 'policy_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(HrLeaveLedgerEntry::class, 'policy_id');
    }
}

<?php

namespace App\Models;

use App\Enums\HR\AlertStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class HrAlert extends Model
{
    protected $attributes = [
        'severity' => 'warning',
        'status' => 'open',
    ];

    protected $fillable = [
        'company_id', 'employee_id', 'type', 'severity', 'status', 'subject_type', 'subject_id',
        'dedupe_key', 'due_at', 'message', 'metadata', 'acknowledged_at', 'acknowledged_by',
        'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'company_id' => 'integer', 'employee_id' => 'integer', 'status' => AlertStatus::class,
        'subject_id' => 'integer', 'due_at' => 'datetime', 'metadata' => 'array',
        'acknowledged_at' => 'datetime', 'resolved_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}

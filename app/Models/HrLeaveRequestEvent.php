<?php

namespace App\Models;

use App\Enums\HR\LeaveRequestStatus;
use App\Models\Concerns\HrAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrLeaveRequestEvent extends Model
{
    use HrAppendOnly;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'leave_request_id', 'from_status', 'to_status', 'action',
        'actor_id', 'reason', 'metadata', 'created_at',
    ];

    protected $casts = [
        'company_id' => 'integer', 'leave_request_id' => 'integer',
        'from_status' => LeaveRequestStatus::class, 'to_status' => LeaveRequestStatus::class,
        'actor_id' => 'integer', 'metadata' => 'array', 'created_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(HrLeaveRequest::class, 'leave_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipBookingOperation extends Model
{
    protected $fillable = [
        'client_uuid',
        'portal_user_id',
        'customer_id',
        'company_id',
        'branch_id',
        'subscription_id',
        'input_queue_revision',
        'request_fingerprint',
        'state',
        'request_snapshot',
        'terms_snapshot',
        'result_snapshot',
        'completed_at',
    ];

    protected $casts = [
        'portal_user_id' => 'integer',
        'customer_id' => 'integer',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'subscription_id' => 'integer',
        'input_queue_revision' => 'integer',
        'request_snapshot' => 'encrypted:array',
        'terms_snapshot' => 'encrypted:array',
        'result_snapshot' => 'encrypted:array',
        'completed_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MealSubscription::class, 'subscription_id');
    }
}

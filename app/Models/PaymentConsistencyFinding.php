<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentConsistencyFinding extends Model
{
    public const STATE_OPEN = 'open';

    public const STATE_RESOLVED = 'resolved';

    protected $fillable = [
        'company_id',
        'branch_id',
        'rule_code',
        'rule_version',
        'subject_type',
        'subject_id',
        'episode_uuid',
        'state',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
        'last_run_id',
        'checkout_id',
        'expected',
        'observed',
        'evidence_fingerprint',
        'alert_dispatch',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'rule_version' => 'integer',
        'subject_id' => 'integer',
        'first_seen_at' => UtcDateTime::class,
        'last_seen_at' => UtcDateTime::class,
        'resolved_at' => UtcDateTime::class,
        'last_run_id' => 'integer',
        'checkout_id' => 'integer',
        'expected' => 'array',
        'observed' => 'array',
        'alert_dispatch' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(PaymentConsistencyRun::class, 'last_run_id');
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(PaymentCheckoutAttempt::class, 'checkout_id');
    }
}

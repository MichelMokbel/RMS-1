<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentConsistencyRun extends Model
{
    public const KIND_TARGETED = 'targeted';

    public const KIND_CATCHUP = 'catchup';

    public const KIND_FULL = 'full';

    public const KIND_MANUAL = 'manual';

    public const STATE_QUEUED = 'queued';

    public const STATE_RUNNING = 'running';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    protected $fillable = [
        'reference',
        'company_id',
        'branch_id',
        'kind',
        'state',
        'requested_by',
        'rule_code',
        'rule_versions',
        'registry_hash',
        'trigger_key',
        'parent_run_id',
        'not_before',
        'target_type',
        'target_id',
        'upper_bound',
        'cursors',
        'heartbeat_at',
        'started_at',
        'completed_at',
        'checked_count',
        'deferred_count',
        'open_count',
        'resolved_count',
        'error_code',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'requested_by' => 'integer',
        'rule_versions' => 'array',
        'parent_run_id' => 'integer',
        'not_before' => UtcDateTime::class,
        'target_id' => 'integer',
        'upper_bound' => 'array',
        'cursors' => 'array',
        'heartbeat_at' => UtcDateTime::class,
        'started_at' => UtcDateTime::class,
        'completed_at' => UtcDateTime::class,
        'checked_count' => 'integer',
        'deferred_count' => 'integer',
        'open_count' => 'integer',
        'resolved_count' => 'integer',
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

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(PaymentConsistencyFinding::class, 'last_run_id');
    }
}

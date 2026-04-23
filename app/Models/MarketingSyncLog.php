<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingSyncLog extends Model
{
    protected $fillable = [
        'platform_account_id',
        'sync_type',
        'status',
        'started_at',
        'completed_at',
        'records_synced',
        'error_message',
        'context',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'context' => 'array',
        'records_synced' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function platformAccount(): BelongsTo
    {
        return $this->belongsTo(MarketingPlatformAccount::class, 'platform_account_id');
    }

    public function scopeRecent(Builder $query, int $limit = 50): Builder
    {
        return $query->orderByDesc('created_at')->limit($limit);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'running', 'started_at' => now()]);
    }

    public function markCompleted(int $recordsSynced): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'records_synced' => $recordsSynced,
        ]);
    }

    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => $errorMessage,
        ]);
    }

    public function getDurationSecondsAttribute(): ?int
    {
        if (! $this->started_at || ! $this->completed_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->completed_at);
    }
}

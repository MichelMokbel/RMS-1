<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingPlatformAccount extends Model
{
    protected $fillable = [
        'platform',
        'external_account_id',
        'account_name',
        'currency',
        'timezone',
        'status',
        'last_synced_at',
        'sync_error',
        'created_by',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function campaigns(): HasMany
    {
        return $this->hasMany(MarketingCampaign::class, 'platform_account_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(MarketingSyncLog::class, 'platform_account_id');
    }

    public function spendSnapshots(): HasMany
    {
        return $this->hasMany(MarketingSpendSnapshot::class, 'platform_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopePlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }

    public function isMetaAccount(): bool
    {
        return $this->platform === 'meta';
    }

    public function isGoogleAccount(): bool
    {
        return $this->platform === 'google';
    }
}

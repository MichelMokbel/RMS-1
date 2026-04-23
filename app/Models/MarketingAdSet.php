<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MarketingAdSet extends Model
{
    protected $fillable = [
        'campaign_id',
        'external_adset_id',
        'name',
        'status',
        'daily_budget_micro',
        'platform_data',
        'last_synced_at',
    ];

    protected $casts = [
        'platform_data' => 'array',
        'last_synced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function ads(): HasMany
    {
        return $this->hasMany(MarketingAd::class, 'ad_set_id');
    }

    public function spendSnapshots(): HasMany
    {
        return $this->hasMany(MarketingSpendSnapshot::class, 'ad_set_id');
    }

    public function assetUsages(): MorphMany
    {
        return $this->morphMany(MarketingAssetUsage::class, 'usageable');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }

    public function getDailyBudgetAttribute(): ?float
    {
        return $this->daily_budget_micro !== null ? $this->daily_budget_micro / 1_000_000 : null;
    }
}

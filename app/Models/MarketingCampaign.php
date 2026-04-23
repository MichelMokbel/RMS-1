<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MarketingCampaign extends Model
{
    protected $fillable = [
        'platform_account_id',
        'external_campaign_id',
        'name',
        'status',
        'objective',
        'daily_budget_micro',
        'lifetime_budget_micro',
        'start_date',
        'end_date',
        'platform_data',
        'last_synced_at',
        'internal_notes',
    ];

    protected $casts = [
        'platform_data' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'last_synced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function platformAccount(): BelongsTo
    {
        return $this->belongsTo(MarketingPlatformAccount::class, 'platform_account_id');
    }

    public function adSets(): HasMany
    {
        return $this->hasMany(MarketingAdSet::class, 'campaign_id');
    }

    public function spendSnapshots(): HasMany
    {
        return $this->hasMany(MarketingSpendSnapshot::class, 'campaign_id');
    }

    public function briefs(): HasMany
    {
        return $this->hasMany(MarketingBrief::class, 'campaign_id');
    }

    public function utms(): HasMany
    {
        return $this->hasMany(MarketingUtm::class, 'campaign_id');
    }

    public function assetUsages(): MorphMany
    {
        return $this->morphMany(MarketingAssetUsage::class, 'usageable');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(MarketingComment::class, 'commentable');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where('name', 'like', '%'.$term.'%');
    }

    public function scopeForPlatform(Builder $query, string $platform): Builder
    {
        return $query->whereHas('platformAccount', fn (Builder $q) => $q->where('platform', $platform));
    }

    public function getDailyBudgetAttribute(): ?float
    {
        return $this->daily_budget_micro !== null ? $this->daily_budget_micro / 1_000_000 : null;
    }

    public function getLifetimeBudgetAttribute(): ?float
    {
        return $this->lifetime_budget_micro !== null ? $this->lifetime_budget_micro / 1_000_000 : null;
    }
}

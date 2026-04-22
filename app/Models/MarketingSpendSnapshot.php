<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingSpendSnapshot extends Model
{
    protected $fillable = [
        'platform_account_id',
        'campaign_id',
        'ad_set_id',
        'snapshot_date',
        'impressions',
        'clicks',
        'spend_micro',
        'conversions',
        'platform_data',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'platform_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function platformAccount(): BelongsTo
    {
        return $this->belongsTo(MarketingPlatformAccount::class, 'platform_account_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function adSet(): BelongsTo
    {
        return $this->belongsTo(MarketingAdSet::class, 'ad_set_id');
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where('snapshot_date', $date);
    }

    public function scopeForMonth(Builder $query, int $year, int $month): Builder
    {
        return $query->whereYear('snapshot_date', $year)->whereMonth('snapshot_date', $month);
    }

    public function getSpendAttribute(): float
    {
        return $this->spend_micro / 1_000_000;
    }
}

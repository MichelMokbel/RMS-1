<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingAd extends Model
{
    protected $fillable = [
        'ad_set_id',
        'external_ad_id',
        'name',
        'status',
        'creative_type',
        'platform_data',
        'last_synced_at',
    ];

    protected $casts = [
        'platform_data' => 'array',
        'last_synced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function adSet(): BelongsTo
    {
        return $this->belongsTo(MarketingAdSet::class, 'ad_set_id');
    }

    public function spendSnapshots(): HasMany
    {
        return $this->hasMany(MarketingSpendSnapshot::class, 'ad_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }
}

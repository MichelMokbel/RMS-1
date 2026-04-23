<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MarketingAsset extends Model
{
    protected $fillable = [
        'name',
        'type',
        's3_key',
        's3_bucket',
        'mime_type',
        'file_size',
        'width',
        'height',
        'duration_seconds',
        'status',
        'current_version',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration_seconds' => 'integer',
        'current_version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(MarketingAssetVersion::class, 'asset_id')->orderBy('version_number');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(MarketingAssetUsage::class, 'asset_id');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(MarketingComment::class, 'commentable');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(MarketingApproval::class, 'approvable');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['archived']);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where('name', 'like', '%'.$term.'%');
    }

    public function isImage(): bool
    {
        return $this->type === 'image';
    }

    public function isVideo(): bool
    {
        return $this->type === 'video';
    }

    public function isPendingReview(): bool
    {
        return $this->status === 'pending_review';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}

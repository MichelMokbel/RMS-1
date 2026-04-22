<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingAssetVersion extends Model
{
    protected $fillable = [
        'asset_id',
        'version_number',
        's3_key',
        'file_size',
        'note',
        'created_by',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'file_size' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MarketingAsset::class, 'asset_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

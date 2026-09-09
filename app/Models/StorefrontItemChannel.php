<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontItemChannel extends Model
{
    protected $fillable = [
        'profile_id',
        'channel_id',
        'is_enabled',
        'item_url',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'profile_id' => 'integer',
        'channel_id' => 'integer',
        'is_enabled' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(StorefrontItemProfile::class, 'profile_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(StorefrontDeliveryChannel::class, 'channel_id');
    }
}

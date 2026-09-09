<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorefrontDeliveryChannel extends Model
{
    public const OPTIONS = [
        'talabat' => 'Talabat',
        'snoonu' => 'Snoonu',
        'rafeeq' => 'Rafeeq',
        'keeta' => 'Keeta',
    ];

    protected $fillable = [
        'company_id',
        'code',
        'label',
        'is_enabled',
        'restaurant_url',
        'display_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'is_enabled' => 'boolean',
        'display_order' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StorefrontItemChannel::class, 'channel_id');
    }
}

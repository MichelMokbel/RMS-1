<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingSetting extends Model
{
    protected $fillable = [
        'meta_app_id',
        'meta_app_secret',
        'meta_system_user_token',
        'meta_business_id',
        'google_developer_token',
        'google_login_customer_id',
        'google_client_id',
        'google_client_secret',
        'google_refresh_token',
        's3_asset_bucket',
        'meta_sync_enabled',
        'google_sync_enabled',
        'updated_by',
    ];

    protected $casts = [
        'meta_sync_enabled' => 'boolean',
        'google_sync_enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Encrypted columns — never read raw; always go through MarketingSettingsService.
    public const ENCRYPTED_COLUMNS = [
        'meta_app_secret',
        'meta_system_user_token',
        'google_developer_token',
        'google_client_secret',
        'google_refresh_token',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontSetting extends Model
{
    public const TIMEZONE = 'Asia/Qatar';

    protected $fillable = [
        'company_id',
        'portal_branch_id',
        'normal_menu_enabled',
        'checkout_upsell_enabled',
        'upsell_category_id',
        'menu_cutoff_time',
        'timezone',
        'delivery_apps_enabled',
        'revision',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'portal_branch_id' => 'integer',
        'normal_menu_enabled' => 'boolean',
        'checkout_upsell_enabled' => 'boolean',
        'upsell_category_id' => 'integer',
        'delivery_apps_enabled' => 'boolean',
        'revision' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function portalBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'portal_branch_id');
    }

    public function upsellCategory(): BelongsTo
    {
        return $this->belongsTo(StorefrontCategory::class, 'upsell_category_id');
    }
}

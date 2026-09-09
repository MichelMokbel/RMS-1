<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorefrontItemProfile extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'menu_item_id',
        'category_id',
        'customer_title',
        'short_description',
        'image_disk',
        'image_path',
        'direct_order_enabled',
        'advance_days',
        'minimum_quantity',
        'quantity_increment',
        'maximum_quantity',
        'is_chef_pick',
        'display_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'menu_item_id' => 'integer',
        'category_id' => 'integer',
        'direct_order_enabled' => 'boolean',
        'advance_days' => 'integer',
        'minimum_quantity' => 'decimal:3',
        'quantity_increment' => 'decimal:3',
        'maximum_quantity' => 'decimal:3',
        'is_chef_pick' => 'boolean',
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(StorefrontCategory::class, 'category_id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(StorefrontItemChannel::class, 'profile_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyDishMenu extends Model
{
    use HasFactory;

    protected $table = 'daily_dish_menus';

    protected $fillable = [
        'branch_id',
        'service_date',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'service_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(DailyDishMenuItem::class, 'daily_dish_menu_id')
            ->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function canEdit(): bool
    {
        return $this->isDraft();
    }

    public function canPublish(): bool
    {
        return $this->isDraft() && $this->items()->count() > 0;
    }
}


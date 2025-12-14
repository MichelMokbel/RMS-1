<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    use HasFactory;

    protected $table = 'recipes';

    protected $fillable = [
        'name',
        'description',
        'category_id',
        'yield_quantity',
        'yield_unit',
        'overhead_pct',
        'selling_price_per_unit',
    ];

    protected $casts = [
        'yield_quantity' => 'decimal:3',
        'overhead_pct' => 'decimal:4',
        'selling_price_per_unit' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class, 'recipe_id');
    }

    public function productions(): HasMany
    {
        return $this->hasMany(RecipeProduction::class, 'recipe_id')->latest('production_date');
    }

    public function normalizedOverheadRate(): float
    {
        $raw = (float) ($this->overhead_pct ?? 0);
        $rate = $raw > 1 ? $raw / 100 : $raw;
        $rate = min(max($rate, 0), 1);

        return $rate;
    }

    public function yieldIsValid(): bool
    {
        return (float) ($this->yield_quantity ?? 0) > 0;
    }
}


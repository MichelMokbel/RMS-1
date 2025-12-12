<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    use HasFactory;

    protected $table = 'menu_items';

    protected $fillable = [
        'code',
        'name',
        'arabic_name',
        'category_id',
        'recipe_id',
        'selling_price_per_unit',
        'tax_rate',
        'is_active',
        'display_order',
    ];

    protected $casts = [
        'selling_price_per_unit' => 'decimal:3',
        'tax_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'display_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function category()
    {
        return class_exists(Category::class) ? $this->belongsTo(Category::class, 'category_id') : null;
    }

    public function recipe()
    {
        return class_exists(Recipe::class) ? $this->belongsTo(Recipe::class, 'recipe_id') : null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('name');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term) {
            $inner->where('code', 'like', '%'.$term.'%')
                ->orWhere('name', 'like', '%'.$term.'%')
                ->orWhere('arabic_name', 'like', '%'.$term.'%');
        });
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function priceWithTax(): ?float
    {
        if ($this->selling_price_per_unit === null) {
            return null;
        }

        return (float) $this->selling_price_per_unit * (1 + ((float) $this->tax_rate / 100));
    }
}

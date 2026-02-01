<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MenuItem extends Model
{
    use HasFactory;

    protected $table = 'menu_items';

    public const UNIT_EACH = 'each';

    public const UNIT_DOZEN = 'dozen';

    public const UNIT_KG = 'kg';

    protected $fillable = [
        'code',
        'name',
        'arabic_name',
        'category_id',
        'recipe_id',
        'selling_price_per_unit',
        'unit',
        'tax_rate',
        'is_active',
        'display_order',
    ];

    /**
     * @return array<string, string>
     */
    public static function unitOptions(): array
    {
        return [
            self::UNIT_EACH => __('Each'),
            self::UNIT_DOZEN => __('Dozen'),
            self::UNIT_KG => __('KG'),
        ];
    }

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

    public function scopeAvailableInBranch(Builder $query, ?int $branchId): Builder
    {
        $branchId = (int) $branchId;
        if ($branchId <= 0 || ! Schema::hasTable('menu_item_branches')) {
            return $query;
        }

        return $query
            ->join('menu_item_branches as mib', function ($join) use ($branchId) {
                $join->on('menu_items.id', '=', 'mib.menu_item_id')
                    ->where('mib.branch_id', '=', $branchId);
            })
            ->select('menu_items.*');
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

    protected static function booted(): void
    {
        static::created(function (MenuItem $item) {
            if (! Schema::hasTable('menu_item_branches')) {
                return;
            }

            $branchId = (int) config('inventory.default_branch_id', 1);
            if ($branchId <= 0) {
                $branchId = 1;
            }

            $exists = DB::table('menu_item_branches')
                ->where('menu_item_id', $item->id)
                ->where('branch_id', $branchId)
                ->exists();

            if (! $exists) {
                DB::table('menu_item_branches')->insert([
                    'menu_item_id' => $item->id,
                    'branch_id' => $branchId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}

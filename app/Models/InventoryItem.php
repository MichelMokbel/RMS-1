<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Category;
use App\Models\Supplier;

class InventoryItem extends Model
{
    use HasFactory;

    protected $table = 'inventory_items';

    protected $fillable = [
        'item_code',
        'name',
        'description',
        'category_id',
        'supplier_id',
        'units_per_package',
        'package_label',
        'unit_of_measure',
        'minimum_stock',
        'cost_per_unit',
        'last_cost_update',
        'location',
        'image_path',
        'status',
    ];

    protected $casts = [
        'units_per_package' => 'decimal:3',
        'minimum_stock' => 'decimal:3',
        'cost_per_unit' => 'decimal:4',
        'last_cost_update' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (InventoryItem $item) {
            if (! Schema::hasTable('inventory_stocks')) {
                return;
            }

            $branchId = (int) config('inventory.default_branch_id', 1);
            if ($branchId <= 0) {
                $branchId = 1;
            }

            InventoryStock::firstOrCreate(
                ['inventory_item_id' => $item->id, 'branch_id' => $branchId],
                ['current_stock' => 0]
            );
        });
    }

    public function category()
    {
        return class_exists(Category::class) ? $this->belongsTo(Category::class, 'category_id') : null;
    }

    public function categoryLabel(string $separator = ' > '): ?string
    {
        $category = $this->relationLoaded('category') ? $this->category : $this->category()->with('parent.parent.parent')->first();

        return $category?->fullName($separator);
    }

    public function supplier()
    {
        return class_exists(Supplier::class) ? $this->belongsTo(Supplier::class, 'supplier_id') : null;
    }

    public function transactions()
    {
        return $this->hasMany(InventoryTransaction::class, 'item_id')->latest('transaction_date');
    }

    public function stocks()
    {
        return $this->hasMany(InventoryStock::class, 'inventory_item_id');
    }

    public function getCurrentStockAttribute($value): float
    {
        if ($value !== null) {
            return (float) $value;
        }

        if (! Schema::hasTable('inventory_stocks')) {
            return 0.0;
        }

        return (float) DB::table('inventory_stocks')
            ->where('inventory_item_id', $this->id)
            ->sum('current_stock');
    }

    public function perUnitCost(): ?float
    {
        if ($this->units_per_package && $this->units_per_package > 0 && $this->cost_per_unit !== null) {
            return (float) $this->cost_per_unit / $this->units_per_package;
        }

        return null;
    }

    public function isLowStock(): bool
    {
        $current = $this->current_stock ?? 0;
        $min = $this->minimum_stock ?? 0;

        return $current <= $min;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $tokens = self::searchTokens($term);
        if ($tokens === []) {
            return $query;
        }

        return $query->where(function (Builder $outer) use ($tokens) {
            foreach ($tokens as $token) {
                $like = '%'.$token.'%';

                $outer->where(function (Builder $inner) use ($like) {
                    $inner->where('item_code', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('location', 'like', $like);
                });
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private static function searchTokens(?string $term): array
    {
        $term = trim((string) $term);
        if ($term === '') {
            return [];
        }

        return collect(preg_split('/[\s\-_]+/u', mb_strtolower($term)) ?: [])
            ->map(fn ($token) => trim((string) $token))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}

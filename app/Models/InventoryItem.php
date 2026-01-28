<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'current_stock',
        'cost_per_unit',
        'last_cost_update',
        'location',
        'image_path',
        'status',
    ];

    protected $casts = [
        'units_per_package' => 'decimal:3',
        'minimum_stock' => 'decimal:3',
        'current_stock' => 'decimal:3',
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
                ['current_stock' => (float) ($item->current_stock ?? 0)]
            );
        });
    }

    public function category()
    {
        return class_exists(Category::class) ? $this->belongsTo(Category::class, 'category_id') : null;
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
}

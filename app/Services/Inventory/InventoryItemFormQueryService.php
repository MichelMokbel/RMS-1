<?php

namespace App\Services\Inventory;

use App\Models\Category;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryItemFormQueryService
{
    public function categories(): Collection
    {
        if (! Schema::hasTable('categories')) {
            return collect();
        }

        return Category::orderBy('name')->get();
    }

    public function suppliers(): Collection
    {
        if (! Schema::hasTable('suppliers')) {
            return collect();
        }

        return Supplier::orderBy('name')->get();
    }

    public function branches(): Collection
    {
        if (! Schema::hasTable('branches')) {
            return collect();
        }

        $q = DB::table('branches')->orderBy('name');
        if (Schema::hasColumn('branches', 'is_active')) {
            $q->where('is_active', 1);
        }

        return $q->get();
    }
}


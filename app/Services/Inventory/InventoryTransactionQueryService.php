<?php

namespace App\Services\Inventory;

use App\Models\Category;
use App\Models\InventoryTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryTransactionQueryService
{
    public function query(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $branchId = (int) ($filters['branch_id'] ?? 0);
        $itemId = (int) ($filters['item_id'] ?? 0);
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        $categoryId = (int) ($filters['category_id'] ?? 0);
        $transactionType = (string) ($filters['transaction_type'] ?? '');
        $referenceType = (string) ($filters['reference_type'] ?? '');
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        return InventoryTransaction::query()
            ->with(['item.category.parent.parent.parent', 'item.supplier', 'user'])
            ->when($branchId > 0, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($itemId > 0, fn (Builder $query) => $query->where('item_id', $itemId))
            ->when($transactionType !== '' && $transactionType !== 'all', fn (Builder $query) => $query->where('transaction_type', $transactionType))
            ->when($referenceType !== '' && $referenceType !== 'all', fn (Builder $query) => $query->where('reference_type', $referenceType))
            ->when($dateFrom, fn (Builder $query) => $query->whereDate('transaction_date', '>=', $dateFrom))
            ->when($dateTo, fn (Builder $query) => $query->whereDate('transaction_date', '<=', $dateTo))
            ->when($supplierId > 0, fn (Builder $query) => $query->whereHas('item', fn (Builder $itemQuery) => $itemQuery->where('supplier_id', $supplierId)))
            ->when($categoryId > 0, fn (Builder $query) => $query->whereHas('item', fn (Builder $itemQuery) => $itemQuery->whereIn('category_id', $this->categoryIdsForFilter($categoryId))))
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $inner) use ($search) {
                    $inner->where('notes', 'like', '%'.$search.'%')
                        ->orWhere('reference_type', 'like', '%'.$search.'%')
                        ->orWhere(DB::raw('CAST(reference_id AS CHAR)'), 'like', '%'.$search.'%')
                        ->orWhereHas('item', function (Builder $itemQuery) use ($search) {
                            $itemQuery->where('name', 'like', '%'.$search.'%')
                                ->orWhere('item_code', 'like', '%'.$search.'%');
                        });
                });
            })
            ->latest('transaction_date')
            ->latest('id');
    }

    public function branches(): Collection
    {
        if (! Schema::hasTable('branches')) {
            return collect();
        }

        $query = DB::table('branches')->orderBy('name');
        if (Schema::hasColumn('branches', 'is_active')) {
            $query->where('is_active', 1);
        }

        return $query->get();
    }

    private function categoryIdsForFilter(int $categoryId): array
    {
        $category = Category::query()->find($categoryId);

        return $category
            ? $category->descendantIdsAndSelf()->all()
            : [$categoryId];
    }
}

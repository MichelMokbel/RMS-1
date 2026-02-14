<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BranchAccessService
{
    /**
     * @return array<int, int>
     */
    public function allowedBranchIds(User $user): array
    {
        if ($user->isAdmin()) {
            return $this->activeBranchIds()->all();
        }

        if (! Schema::hasTable('user_branch_access')) {
            return [];
        }

        return DB::table('user_branch_access')
            ->where('user_id', (int) $user->id)
            ->pluck('branch_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function canAccessBranch(User $user, int $branchId): bool
    {
        if ($branchId <= 0) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return in_array($branchId, $this->allowedBranchIds($user), true);
    }

    public function applyBranchScope(EloquentBuilder|QueryBuilder $query, User $user, string $column = 'branch_id'): EloquentBuilder|QueryBuilder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $ids = $this->allowedBranchIds($user);
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $ids);
    }

    /**
     * Extract branch IDs from common route/body/query keys.
     *
     * @return array<int, int>
     */
    public function requestedBranchIds(Request $request): array
    {
        $keys = ['branch', 'branchId', 'branch_id'];
        $ids = [];

        foreach ($keys as $key) {
            $routeValue = $request->route($key);
            if ($routeValue !== null && is_scalar($routeValue)) {
                $id = (int) $routeValue;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }

            $inputValue = $request->input($key);
            if ($inputValue !== null && is_scalar($inputValue)) {
                $id = (int) $inputValue;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return collect($ids)->unique()->values()->all();
    }

    /**
     * @return Collection<int, int>
     */
    public function activeBranchIds(): Collection
    {
        if (! Schema::hasTable('branches')) {
            return collect();
        }

        $query = DB::table('branches')->select('id');
        if (Schema::hasColumn('branches', 'is_active')) {
            $query->where('is_active', 1);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->values();
    }
}


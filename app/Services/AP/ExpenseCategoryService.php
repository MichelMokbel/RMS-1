<?php

namespace App\Services\AP;

use App\Models\ExpenseCategory;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Facades\DB;

class ExpenseCategoryService
{
    public function __construct(
        protected AccountingAuditLogService $auditLog,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, active?: bool}  $data
     */
    public function save(array $data, int $actorId, ?ExpenseCategory $category = null): ExpenseCategory
    {
        return DB::transaction(function () use ($data, $actorId, $category): ExpenseCategory {
            $locked = $category
                ? ExpenseCategory::query()->lockForUpdate()->findOrFail($category->id)
                : new ExpenseCategory;
            $before = $locked->exists ? $this->snapshot($locked) : null;

            $locked->fill([
                'name' => trim($data['name']),
                'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                    ? trim((string) $data['description'])
                    : null,
                'active' => (bool) ($data['active'] ?? true),
            ])->save();

            $this->auditLog->log(
                $before ? 'expense_category.updated' : 'expense_category.created',
                $actorId,
                $locked,
                ['before' => $before, 'after' => $this->snapshot($locked)],
            );

            return $locked->refresh();
        });
    }

    public function setActive(ExpenseCategory $category, bool $active, int $actorId): ExpenseCategory
    {
        return DB::transaction(function () use ($category, $active, $actorId): ExpenseCategory {
            $locked = ExpenseCategory::query()->lockForUpdate()->findOrFail($category->id);
            $before = $this->snapshot($locked);
            $locked->update(['active' => $active]);

            $this->auditLog->log(
                $active ? 'expense_category.activated' : 'expense_category.deactivated',
                $actorId,
                $locked,
                ['before' => $before, 'after' => $this->snapshot($locked)],
            );

            return $locked->refresh();
        });
    }

    /**
     * @return array{id: int, name: string, description: string|null, active: bool}
     */
    private function snapshot(ExpenseCategory $category): array
    {
        return [
            'id' => (int) $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'active' => (bool) $category->active,
        ];
    }
}

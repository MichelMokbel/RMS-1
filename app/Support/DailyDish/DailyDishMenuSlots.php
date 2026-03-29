<?php

namespace App\Support\DailyDish;

class DailyDishMenuSlots
{
    /**
     * @return array<int, array{slot_label:string,menu_item_id:?int,role:string,sort_order:int,is_required:bool}>
     */
    public static function defaultRows(): array
    {
        return [
            self::makeRow('Main 1', 'main', 0),
            self::makeRow('Main 2', 'main', 1),
            self::makeRow('Main 3', 'main', 2),
            self::makeRow('Salad', 'salad', 3),
            self::makeRow('Dessert', 'dessert', 4),
        ];
    }

    /**
     * @param  iterable<int, mixed>  $items
     * @return array<int, array{slot_label:string,menu_item_id:?int,role:string,sort_order:int,is_required:bool}>
     */
    public static function normalizeRows(iterable $items): array
    {
        $rows = self::defaultRows();
        $mainRows = [];
        $saladRow = null;
        $dessertRow = null;

        foreach ($items as $item) {
            $role = (string) data_get($item, 'role', 'main');
            $normalized = [
                'menu_item_id' => data_get($item, 'menu_item_id'),
                'role' => $role,
                'sort_order' => (int) data_get($item, 'sort_order', 0),
                'is_required' => (bool) data_get($item, 'is_required', false),
            ];

            if ($role === 'salad') {
                if ($saladRow === null) {
                    $saladRow = $normalized;
                }
                continue;
            }

            if ($role === 'dessert') {
                if ($dessertRow === null) {
                    $dessertRow = $normalized;
                }
                continue;
            }

            if (! in_array($role, ['main', 'diet', 'vegetarian'], true)) {
                continue;
            }

            $mainRows[] = $normalized;
        }

        usort($mainRows, fn ($a, $b) => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0));

        foreach (array_slice($mainRows, 0, 3) as $index => $mainRow) {
            $rows[$index]['menu_item_id'] = (int) ($mainRow['menu_item_id'] ?? 0) ?: null;
        }

        if ($saladRow !== null) {
            $rows[3]['menu_item_id'] = (int) ($saladRow['menu_item_id'] ?? 0) ?: null;
        }

        if ($dessertRow !== null) {
            $rows[4]['menu_item_id'] = (int) ($dessertRow['menu_item_id'] ?? 0) ?: null;
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{menu_item_id:int,role:string,sort_order:int,is_required:bool}>
     */
    public static function selectedItems(array $rows): array
    {
        return collect($rows)
            ->map(function (array $row): ?array {
                $menuItemId = (int) ($row['menu_item_id'] ?? 0);
                if ($menuItemId <= 0) {
                    return null;
                }

                return [
                    'menu_item_id' => $menuItemId,
                    'role' => (string) ($row['role'] ?? 'main'),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'is_required' => (bool) ($row['is_required'] ?? false),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private static function makeRow(string $label, string $role, int $sortOrder): array
    {
        return [
            'slot_label' => $label,
            'menu_item_id' => null,
            'role' => $role,
            'sort_order' => $sortOrder,
            'is_required' => false,
        ];
    }
}

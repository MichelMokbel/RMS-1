<?php

namespace App\Services\OrderSheet;

use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderSheet;
use App\Models\OrderSheetEntry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class OrderSheetEditorService
{
    public function __construct(
        protected OrderSheetLocationService $locations,
    ) {}

    /**
     * @return array{date: string, menuItems: array<int, array<string, mixed>>, rows: array<int, array<string, mixed>>}
     */
    public function snapshot(string $date): array
    {
        $menuItems = $this->menuItems($date);
        $sheet = OrderSheet::with([
            'entries' => fn ($query) => $query->withContent(),
            'entries.quantities',
            'entries.extras',
            'entries.order',
        ])->where('sheet_date', $date)->first();

        $emptyQuantities = collect($menuItems)->mapWithKeys(fn ($item) => [$item['id'] => 0])->all();
        $menuItemToColumn = collect($menuItems)->pluck('id', 'menu_item_id');
        $savedLocations = $this->locations->forOrders(new EloquentCollection(
            $sheet?->entries->pluck('order')->filter()->all() ?? []
        ));

        $rows = $sheet?->entries->map(function (OrderSheetEntry $entry) use ($emptyQuantities, $savedLocations) {
            $quantities = $emptyQuantities;
            foreach ($entry->quantities as $quantity) {
                if (array_key_exists($quantity->daily_dish_menu_item_id, $quantities)) {
                    $quantities[$quantity->daily_dish_menu_item_id] = (int) $quantity->quantity;
                }
            }

            return [
                'key' => 'entry-'.$entry->id,
                'order_id' => $entry->order_id,
                'customer_id' => $entry->customer_id,
                'customer_name' => $entry->customer_name,
                'location' => filled($entry->location)
                    ? $entry->location
                    : $savedLocations->get($entry->order_id, ''),
                'quantities' => $quantities,
                'extras' => $entry->extras->map(fn ($extra) => [
                    'menu_item_id' => (int) $extra->menu_item_id,
                    'name' => $extra->menu_item_name,
                    'quantity' => (int) $extra->quantity,
                ])->values()->all(),
                'remarks' => $entry->remarks ?? '',
            ];
        })->values()->all() ?? [];

        $linkedOrderIds = collect($rows)->pluck('order_id')->filter()->map(fn ($id) => (int) $id)->all();
        $excludedOrderIds = collect($sheet?->excluded_order_ids ?? [])->map(fn ($id) => (int) $id)->filter()->all();
        $orders = Order::with(['items.menuItem'])
            ->where('is_daily_dish', true)
            ->whereDate('scheduled_date', $date)
            ->whereNotIn('status', ['Cancelled'])
            ->whereNotIn('id', array_values(array_unique([...$linkedOrderIds, ...$excludedOrderIds])))
            ->orderBy('customer_name_snapshot')
            ->get();
        $orderLocations = $this->locations->forOrders($orders);

        foreach ($orders as $order) {
            $quantities = $emptyQuantities;
            $extras = [];
            foreach ($order->items as $item) {
                if (! $item->menu_item_id) {
                    continue;
                }
                $columnId = $menuItemToColumn->get($item->menu_item_id);
                if ($columnId) {
                    $quantities[$columnId] += (int) round($item->quantity);
                } else {
                    $extras[] = [
                        'menu_item_id' => (int) $item->menu_item_id,
                        'name' => $item->menuItem?->name ?? $item->description_snapshot,
                        'quantity' => (int) round($item->quantity),
                    ];
                }
            }

            $rows[] = [
                'key' => 'order-'.$order->id,
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'customer_name' => $order->customer_name_snapshot,
                'location' => $orderLocations->get($order->id, ''),
                'quantities' => $quantities,
                'extras' => $extras,
                'remarks' => $order->notes ?? '',
            ];
        }

        return [
            'date' => $date,
            'menuItems' => $menuItems,
            'rows' => array_values($rows),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $removedOrderIds
     */
    public function save(string $date, array $rows, array $removedOrderIds = []): void
    {
        $allowedColumns = collect($this->menuItems($date))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $extraIds = collect($rows)->flatMap(fn ($row) => collect($row['extras'] ?? [])->pluck('menu_item_id'))
            ->map(fn ($id) => (int) $id)->filter()->unique()->all();
        $extraNames = MenuItem::whereIn('id', $extraIds)->pluck('name', 'id');
        $validRemovedOrderIds = Order::query()
            ->whereIn('id', collect($removedOrderIds)->map(fn ($id) => (int) $id)->filter()->unique()->all())
            ->where('is_daily_dish', true)
            ->whereDate('scheduled_date', $date)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($date, $rows, $allowedColumns, $extraNames, $validRemovedOrderIds) {
            $sheet = OrderSheet::where('sheet_date', $date)->lockForUpdate()->first();
            if (! $sheet) {
                $sheet = OrderSheet::create(['sheet_date' => $date]);
            }

            $sheet->update([
                'excluded_order_ids' => collect($sheet->excluded_order_ids ?? [])
                    ->merge($validRemovedOrderIds)
                    ->map(fn ($id) => (int) $id)
                    ->filter()->unique()->values()->all(),
            ]);
            $sheet->entries()->delete();

            foreach ($rows as $row) {
                $customerName = trim((string) ($row['customer_name'] ?? ''));
                if ($customerName === '') {
                    continue;
                }

                $customerId = filled($row['customer_id'] ?? null)
                    ? Customer::active()->whereKey((int) $row['customer_id'])->value('id')
                    : null;
                $orderId = filled($row['order_id'] ?? null)
                    ? Order::query()->whereKey((int) $row['order_id'])
                        ->where('is_daily_dish', true)->whereDate('scheduled_date', $date)->value('id')
                    : null;
                $entry = $sheet->entries()->create([
                    'customer_id' => $customerId,
                    'customer_name' => $customerName,
                    'location' => filled($row['location'] ?? null) ? trim((string) $row['location']) : null,
                    'remarks' => filled($row['remarks'] ?? null) ? trim((string) $row['remarks']) : null,
                    'order_id' => $orderId,
                ]);

                foreach ($allowedColumns as $columnId) {
                    $quantity = (int) ($row['quantities'][$columnId] ?? 0);
                    if ($quantity > 0) {
                        $entry->quantities()->create([
                            'daily_dish_menu_item_id' => $columnId,
                            'quantity' => $quantity,
                        ]);
                    }
                }

                foreach ($row['extras'] ?? [] as $extra) {
                    $menuItemId = (int) ($extra['menu_item_id'] ?? 0);
                    $quantity = (int) ($extra['quantity'] ?? 0);
                    if ($quantity <= 0 || ! $extraNames->has($menuItemId)) {
                        continue;
                    }
                    $entry->extras()->create([
                        'menu_item_id' => $menuItemId,
                        'menu_item_name' => $extraNames->get($menuItemId),
                        'quantity' => $quantity,
                    ]);
                }
            }
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function menuItems(string $date): array
    {
        $menu = DailyDishMenu::with(['items.menuItem'])
            ->whereDate('service_date', $date)
            ->first();
        $rolePriority = ['main' => 0, 'diet' => 1, 'vegetarian' => 2, 'salad' => 3, 'dessert' => 4];

        return $menu?->items
            ->sortBy(fn ($item) => $rolePriority[$item->role] ?? 5)
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'menu_item_id' => (int) $item->menu_item_id,
                'name' => $item->menuItem?->name ?? __('Unnamed dish'),
                'role' => $item->role ?? '',
            ])->values()->all() ?? [];
    }
}

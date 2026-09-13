<?php

namespace App\Services\OrderSheet;

use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\MealSubscription;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderSheet;
use App\Models\OrderSheetEntry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderSheetEditorService
{
    private const PORTION_TYPES = ['plate', 'half', 'full'];

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

        $emptyQuantities = collect($menuItems)->mapWithKeys(fn ($item) => [$item['id'] => $this->emptyPortions()])->all();
        $menuItemToColumn = collect($menuItems)->pluck('id', 'menu_item_id');
        $columnRoles = collect($menuItems)->pluck('role', 'id');
        $savedLocations = $this->locations->forOrders(new EloquentCollection(
            $sheet?->entries->pluck('order')->filter()->all() ?? []
        ));

        $rows = $sheet?->entries->map(function (OrderSheetEntry $entry) use ($emptyQuantities, $savedLocations) {
            $quantities = $emptyQuantities;
            foreach ($entry->quantities as $quantity) {
                if (array_key_exists($quantity->daily_dish_menu_item_id, $quantities)) {
                    $portionType = in_array($quantity->portion_type, self::PORTION_TYPES, true)
                        ? $quantity->portion_type
                        : 'plate';
                    $quantities[$quantity->daily_dish_menu_item_id][$portionType] += (int) $quantity->quantity;
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
                    'portion_type' => $this->normalizePortionType($extra->portion_type),
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
                    $portionType = $this->portionTypeForOrderItem(
                        $order,
                        $item->description_snapshot,
                        $columnRoles->get($columnId, '')
                    );
                    $quantities[$columnId][$portionType] += (int) round($item->quantity);
                } else {
                    $extras[] = [
                        'menu_item_id' => (int) $item->menu_item_id,
                        'name' => $item->menuItem?->name ?? $item->description_snapshot,
                        'portion_type' => $this->portionTypeFromDescription($item->description_snapshot),
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
            'rows' => $this->applySubscriptionAppetizers($date, array_values($rows), $menuItems),
        ];
    }

    /**
     * @param  array<int, int>  $customerIds
     * @return Collection<int, array{subscription_id: int, appetizer: array{menu_item_id: int, name: string}|null}>
     */
    public function subscriptionBenefits(string $date, array $customerIds): Collection
    {
        $customerIds = collect($customerIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($customerIds === []) {
            return collect();
        }

        $subscriptions = MealSubscription::with(['days', 'pauses'])
            ->whereIn('customer_id', $customerIds)
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $date)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (MealSubscription $subscription) => $subscription->isActiveOn($date))
            ->filter(fn (MealSubscription $subscription) => $subscription->plan_meals_total === null
                || (int) ($subscription->meals_used ?? 0) < (int) $subscription->plan_meals_total)
            ->unique('customer_id');
        $appetizer = $this->defaultSubscriptionAppetizer();

        return $subscriptions->mapWithKeys(fn (MealSubscription $subscription) => [
            (int) $subscription->customer_id => [
                'subscription_id' => (int) $subscription->id,
                'appetizer' => $appetizer ? [
                    'menu_item_id' => (int) $appetizer->id,
                    'name' => $appetizer->name,
                ] : null,
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $removedOrderIds
     */
    public function save(string $date, array $rows, array $removedOrderIds = []): void
    {
        $menuItems = $this->menuItems($date);
        $allowedColumns = collect($menuItems)->mapWithKeys(fn ($item) => [(int) $item['id'] => $item['role']])->all();
        $rows = $this->applySubscriptionAppetizers($date, $rows, $menuItems, true);
        foreach ($rows as $index => $row) {
            foreach ($allowedColumns as $columnId => $role) {
                if ($role !== 'main'
                    && ((int) ($row['quantities'][$columnId]['half'] ?? 0) > 0
                        || (int) ($row['quantities'][$columnId]['full'] ?? 0) > 0)) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.quantities.{$columnId}" => __('Portions can only be selected for main dishes.'),
                    ]);
                }
            }
        }
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

                foreach ($allowedColumns as $columnId => $role) {
                    $portionTypes = $role === 'main' ? self::PORTION_TYPES : ['plate'];
                    foreach ($portionTypes as $portionType) {
                        $quantity = (int) ($row['quantities'][$columnId][$portionType] ?? 0);
                        if ($quantity > 0) {
                            $entry->quantities()->create([
                                'daily_dish_menu_item_id' => $columnId,
                                'portion_type' => $portionType,
                                'quantity' => $quantity,
                            ]);
                        }
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
                        'portion_type' => $this->normalizePortionType($extra['portion_type'] ?? null),
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

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $menuItems
     * @return array<int, array<string, mixed>>
     */
    private function applySubscriptionAppetizers(string $date, array $rows, array $menuItems, bool $rejectMissing = false): array
    {
        $benefits = $this->subscriptionBenefits($date, collect($rows)->pluck('customer_id')->all());
        $mainColumnIds = collect($menuItems)
            ->where('role', 'main')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return collect($rows)->map(function (array $row, int $index) use ($benefits, $mainColumnIds, $rejectMissing) {
            $benefit = $benefits->get((int) ($row['customer_id'] ?? 0));
            $row['has_subscription'] = $benefit !== null;
            $row['subscription_appetizer'] = $benefit['appetizer'] ?? null;
            if (! $benefit) {
                return $row;
            }

            $mainQuantity = collect($mainColumnIds)->sum(fn ($columnId) => collect(self::PORTION_TYPES)
                ->sum(fn ($portionType) => max(0, (int) ($row['quantities'][$columnId][$portionType] ?? 0))));
            $appetizer = $benefit['appetizer'];
            if (! $appetizer) {
                if ($rejectMissing && $mainQuantity > 0) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.extras" => __('The default subscription appetizer is not configured.'),
                    ]);
                }

                return $row;
            }

            $extras = collect($row['extras'] ?? [])
                ->reject(fn ($extra) => (int) ($extra['menu_item_id'] ?? 0) === $appetizer['menu_item_id'])
                ->values();
            if ($mainQuantity > 0) {
                $extras->push([
                    'menu_item_id' => $appetizer['menu_item_id'],
                    'name' => $appetizer['name'],
                    'portion_type' => 'plate',
                    'quantity' => $mainQuantity,
                ]);
            }
            $row['extras'] = $extras->all();

            return $row;
        })->all();
    }

    private function defaultSubscriptionAppetizer(): ?MenuItem
    {
        $code = trim((string) config('subscriptions.default_appetizer_code', ''));
        if ($code === '') {
            return null;
        }

        return MenuItem::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first(['id', 'name']);
    }

    /** @return array{plate: int, half: int, full: int} */
    private function emptyPortions(): array
    {
        return ['plate' => 0, 'half' => 0, 'full' => 0];
    }

    private function portionTypeForOrderItem(Order $order, string $description, string $role): string
    {
        if ($role !== 'main') {
            return 'plate';
        }
        if (stripos($description, '(Half Portion)') !== false) {
            return 'half';
        }
        if (stripos($description, '(Full Portion)') !== false) {
            return 'full';
        }

        return in_array($order->daily_dish_portion_type, ['half', 'full'], true)
            ? $order->daily_dish_portion_type
            : 'plate';
    }

    private function portionTypeFromDescription(string $description): string
    {
        if (stripos($description, '(Half Portion)') !== false) {
            return 'half';
        }
        if (stripos($description, '(Full Portion)') !== false) {
            return 'full';
        }

        return 'plate';
    }

    private function normalizePortionType(mixed $portionType): string
    {
        return in_array($portionType, self::PORTION_TYPES, true) ? $portionType : 'plate';
    }
}

<?php

namespace App\Services\Orders;

use App\Mail\DailyDishOrderAdminMail;
use App\Mail\DailyDishOrderCustomerMail;
use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\MealPlanRequest;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Pricing\MealPlanPricingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CustomerDailyDishOrderService
{
    private const PUBLIC_ORDER_BRANCH_ID = 1;

    public function __construct(
        private readonly OrderNumberService $numberService,
        private readonly MealPlanPricingService $pricingService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success:bool,order_ids:array<int, int>,meal_plan_request_id:int|null,email_sent_admin:bool,email_sent_customer:bool}
     */
    public function create(User $user, array $payload): array
    {
        $customer = $user->customer;
        if (! $customer instanceof Customer) {
            throw ValidationException::withMessages([
                'customer' => __('A linked customer account is required.'),
            ]);
        }

        $branchId = self::PUBLIC_ORDER_BRANCH_ID;
        if (Schema::hasTable('branches')) {
            $q = DB::table('branches')->where('id', $branchId);
            if (Schema::hasColumn('branches', 'is_active')) {
                $q->where('is_active', 1);
            }

            if (! $q->exists()) {
                throw ValidationException::withMessages([
                    'branch_id' => __('Invalid branch.'),
                ]);
            }
        }

        $items = collect($payload['items'] ?? []);
        $usesNewShape = $items->contains(fn ($row) => isset($row['mains']) && is_array($row['mains']));
        $isSubscriptionRequest = $this->isSubscriptionRequest($payload);
        $source = $isSubscriptionRequest ? 'Subscription' : 'Website';
        $planPrice = null;
        $appetizerMenuItemId = null;

        if ($isSubscriptionRequest) {
            $planPrice = $this->fixedSubscriptionPrice((string) ($payload['mealPlan'] ?? ''));
            if ($planPrice === null) {
                throw ValidationException::withMessages([
                    'mealPlan' => __('Unsupported meal plan.'),
                ]);
            }

            $appetizerMenuItemId = $this->resolveDefaultAppetizerMenuItemId();
            if ($appetizerMenuItemId === null) {
                throw ValidationException::withMessages([
                    'mealPlan' => __('Default appetizer item is not configured.'),
                ]);
            }
        }

        $groups = $items->groupBy('key');
        $createdOrderIds = [];
        $leadId = null;

        DB::transaction(function () use (
            $groups,
            $payload,
            $branchId,
            $user,
            $customer,
            &$createdOrderIds,
            &$leadId,
            $usesNewShape,
            $isSubscriptionRequest,
            $source,
            $planPrice,
            $appetizerMenuItemId
        ): void {
            foreach ($groups as $date => $groupItems) {
                $websiteDayTotal = ! $isSubscriptionRequest
                    ? $this->resolveWebsiteDayTotal($groupItems, (string) $date)
                    : null;

                $menu = DailyDishMenu::query()
                    ->where('branch_id', $branchId)
                    ->whereDate('service_date', $date)
                    ->where('status', 'published')
                    ->with(['items.menuItem'])
                    ->first();

                if (! $menu) {
                    throw ValidationException::withMessages([
                        'items' => __("No published daily dish menu for {$date}."),
                    ]);
                }

                $menuMainMap = $menu->items
                    ->filter(fn ($row) => $row->role === 'main' && $row->menuItem)
                    ->mapWithKeys(fn ($row) => [mb_strtolower(trim((string) $row->menuItem->name)) => (int) $row->menu_item_id])
                    ->all();

                $saladMenuItem = $menu->items->firstWhere('role', 'salad')?->menuItem;
                $dessertMenuItem = $menu->items->firstWhere('role', 'dessert')?->menuItem;
                $lineGroups = [];

                if ($usesNewShape) {
                    $mainSelections = [];
                    $saladQty = 0;
                    $dessertQty = 0;

                    foreach ($groupItems as $row) {
                        $saladQty += (int) ($row['salad_qty'] ?? 0);
                        $dessertQty += (int) ($row['dessert_qty'] ?? 0);

                        $mains = is_array($row['mains'] ?? null) ? $row['mains'] : [];
                        foreach ($mains as $mainRow) {
                            $mainName = trim((string) ($mainRow['name'] ?? $mainRow['main'] ?? ''));
                            $portion = strtolower((string) ($mainRow['portion'] ?? 'plate'));
                            $qty = (int) ($mainRow['qty'] ?? 0);

                            if ($qty <= 0) {
                                continue;
                            }

                            if ($mainName === '') {
                                throw ValidationException::withMessages([
                                    'items' => __("Main dish is required for {$date}."),
                                ]);
                            }

                            if ($this->pricingService->portionPrice($portion) === null) {
                                throw ValidationException::withMessages([
                                    'items' => __("Unsupported portion '{$portion}' for {$date}."),
                                ]);
                            }

                            $mainSelections[] = [
                                'name' => $mainName,
                                'portion' => $portion,
                                'qty' => $qty,
                                'menu_item_id' => isset($mainRow['menu_item_id']) ? (int) $mainRow['menu_item_id'] : null,
                            ];
                        }
                    }

                    if ($mainSelections === []) {
                        throw ValidationException::withMessages([
                            'items' => __("Please select at least one main dish for {$date}."),
                        ]);
                    }

                    if (! empty($payload['mealPlan'])) {
                        foreach ($mainSelections as $sel) {
                            $menuItemId = $sel['menu_item_id'];
                            if (! $menuItemId) {
                                $mainKey = mb_strtolower(trim((string) $sel['name']));
                                $menuItemId = $mainKey !== '' ? ($menuMainMap[$mainKey] ?? null) : null;
                            }

                            if (! $menuItemId) {
                                throw ValidationException::withMessages([
                                    'items' => __("Could not resolve main item for {$date}."),
                                ]);
                            }

                            $key = 'plan|'.$menuItemId;
                            if (! isset($lineGroups[$key])) {
                                $lineGroups[$key] = [
                                    'menu_item_id' => $menuItemId,
                                    'qty' => 0,
                                    'unit_price' => 0,
                                    'label' => 'Meal Plan',
                                    'role' => 'main',
                                ];
                            }
                            $lineGroups[$key]['qty'] += $sel['qty'];
                        }

                        if ($saladQty > 0) {
                            $saladMenuItemId = $saladMenuItem?->getKey();
                            if (! $saladMenuItemId) {
                                throw ValidationException::withMessages([
                                    'items' => __("Could not resolve salad item for {$date}."),
                                ]);
                            }

                            $lineGroups['plan-salad|'.$saladMenuItemId] = [
                                'menu_item_id' => $saladMenuItemId,
                                'qty' => $saladQty,
                                'unit_price' => 0,
                                'label' => 'Salad',
                                'role' => 'salad',
                            ];
                        }

                        if ($dessertQty > 0) {
                            $dessertMenuItemId = $dessertMenuItem?->getKey();
                            if (! $dessertMenuItemId) {
                                throw ValidationException::withMessages([
                                    'items' => __("Could not resolve dessert item for {$date}."),
                                ]);
                            }

                            $lineGroups['plan-dessert|'.$dessertMenuItemId] = [
                                'menu_item_id' => $dessertMenuItemId,
                                'qty' => $dessertQty,
                                'unit_price' => 0,
                                'label' => 'Dessert',
                                'role' => 'dessert',
                            ];
                        }
                    } else {
                        $hasNonPlate = collect($mainSelections)->contains(fn (array $sel) => $sel['portion'] !== 'plate');

                        if ($hasNonPlate) {
                            foreach ($mainSelections as $sel) {
                                $portion = $sel['portion'];
                                $menuItemId = $sel['menu_item_id'];
                                if (! $menuItemId) {
                                    $mainKey = mb_strtolower(trim((string) $sel['name']));
                                    $menuItemId = $mainKey !== '' ? ($menuMainMap[$mainKey] ?? null) : null;
                                }

                                if (! $menuItemId) {
                                    throw ValidationException::withMessages([
                                        'items' => __("Could not resolve main item for {$date}."),
                                    ]);
                                }

                                $key = $portion.'|'.$menuItemId;
                                if (! isset($lineGroups[$key])) {
                                    $lineGroups[$key] = [
                                        'menu_item_id' => $menuItemId,
                                        'qty' => 0,
                                        'unit_price' => $this->pricingService->portionPrice($portion)
                                            ?? $this->pricingService->portionPrice('plate')
                                            ?? 0.0,
                                        'label' => $this->pricingService->portionLabel($portion),
                                        'role' => 'main',
                                    ];
                                }
                                $lineGroups[$key]['qty'] += $sel['qty'];
                            }

                            if ($saladQty > 0) {
                                $saladMenuItemId = $saladMenuItem?->getKey();
                                if (! $saladMenuItemId) {
                                    throw ValidationException::withMessages([
                                        'items' => __("Could not resolve salad item for {$date}."),
                                    ]);
                                }
                                $lineGroups['salad-addon|'.$saladMenuItemId] = [
                                    'menu_item_id' => $saladMenuItemId,
                                    'qty' => $saladQty,
                                    'unit_price' => $this->pricingService->addonPrice('salad') ?? 0.0,
                                    'label' => 'Salad Add-on',
                                    'role' => 'salad',
                                ];
                            }

                            if ($dessertQty > 0) {
                                $dessertMenuItemId = $dessertMenuItem?->getKey();
                                if (! $dessertMenuItemId) {
                                    throw ValidationException::withMessages([
                                        'items' => __("Could not resolve dessert item for {$date}."),
                                    ]);
                                }
                                $lineGroups['dessert-addon|'.$dessertMenuItemId] = [
                                    'menu_item_id' => $dessertMenuItemId,
                                    'qty' => $dessertQty,
                                    'unit_price' => $this->pricingService->addonPrice('dessert') ?? 0.0,
                                    'label' => 'Dessert Add-on',
                                    'role' => 'dessert',
                                ];
                            }
                        } else {
                            $mainsWithQty = [];
                            foreach ($mainSelections as $sel) {
                                for ($i = 0; $i < $sel['qty']; $i++) {
                                    $mainsWithQty[] = $sel['name'];
                                }
                            }

                            $mainCount = count($mainsWithQty);
                            $bundledSaladQty = min($saladQty, $mainCount);
                            $bundledDessertQty = min($dessertQty, $mainCount);
                            $extraSaladQty = max(0, $saladQty - $bundledSaladQty);
                            $extraDessertQty = max(0, $dessertQty - $bundledDessertQty);

                            $bundles = $this->buildBundles($mainsWithQty, $bundledSaladQty, $bundledDessertQty);
                            foreach ($bundles as $bundle) {
                                $mainKey = mb_strtolower(trim((string) $bundle['main']));
                                $menuItemId = $mainKey !== '' ? ($menuMainMap[$mainKey] ?? null) : null;
                                $bundleType = (string) $bundle['type'];
                                $bundlePrice = $this->pricingService->bundlePrice($bundleType);

                                if (! $menuItemId || $bundlePrice === null) {
                                    throw ValidationException::withMessages([
                                        'items' => __("Could not resolve meal selection for {$date}."),
                                    ]);
                                }

                                $key = $bundleType.'|'.$menuItemId;
                                if (! isset($lineGroups[$key])) {
                                    $label = match ($bundleType) {
                                        'full' => 'Full Meal',
                                        'mainSalad' => 'Main + Salad',
                                        'mainDessert' => 'Main + Dessert',
                                        'mainOnly' => 'Main Only',
                                        default => $bundleType,
                                    };

                                    $lineGroups[$key] = [
                                        'menu_item_id' => $menuItemId,
                                        'qty' => 0,
                                        'unit_price' => $bundlePrice,
                                        'label' => $label,
                                        'role' => 'main',
                                    ];
                                }

                                $lineGroups[$key]['qty'] += 1;

                                if (! empty($bundle['salad'])) {
                                    $saladMenuItemId = $saladMenuItem?->getKey();
                                    if (! $saladMenuItemId) {
                                        throw ValidationException::withMessages([
                                            'items' => __("Could not resolve salad item for {$date}."),
                                        ]);
                                    }

                                    $saladKey = 'bundle-salad|'.$saladMenuItemId;
                                    if (! isset($lineGroups[$saladKey])) {
                                        $lineGroups[$saladKey] = [
                                            'menu_item_id' => $saladMenuItemId,
                                            'qty' => 0,
                                            'unit_price' => 0.0,
                                            'label' => 'Salad',
                                            'role' => 'salad',
                                        ];
                                    }

                                    $lineGroups[$saladKey]['qty'] += 1;
                                }

                                if (! empty($bundle['dessert'])) {
                                    $dessertMenuItemId = $dessertMenuItem?->getKey();
                                    if (! $dessertMenuItemId) {
                                        throw ValidationException::withMessages([
                                            'items' => __("Could not resolve dessert item for {$date}."),
                                        ]);
                                    }

                                    $dessertKey = 'bundle-dessert|'.$dessertMenuItemId;
                                    if (! isset($lineGroups[$dessertKey])) {
                                        $lineGroups[$dessertKey] = [
                                            'menu_item_id' => $dessertMenuItemId,
                                            'qty' => 0,
                                            'unit_price' => 0.0,
                                            'label' => 'Dessert',
                                            'role' => 'dessert',
                                        ];
                                    }

                                    $lineGroups[$dessertKey]['qty'] += 1;
                                }
                            }

                            if ($extraSaladQty > 0) {
                                $saladMenuItemId = $saladMenuItem?->getKey();
                                if (! $saladMenuItemId) {
                                    throw ValidationException::withMessages([
                                        'items' => __("Could not resolve salad item for {$date}."),
                                    ]);
                                }

                                $lineGroups['salad-addon|'.$saladMenuItemId] = [
                                    'menu_item_id' => $saladMenuItemId,
                                    'qty' => $extraSaladQty,
                                    'unit_price' => $this->pricingService->addonPrice('salad') ?? 0.0,
                                    'label' => 'Salad Add-on',
                                    'role' => 'salad',
                                ];
                            }

                            if ($extraDessertQty > 0) {
                                $dessertMenuItemId = $dessertMenuItem?->getKey();
                                if (! $dessertMenuItemId) {
                                    throw ValidationException::withMessages([
                                        'items' => __("Could not resolve dessert item for {$date}."),
                                    ]);
                                }

                                $lineGroups['dessert-addon|'.$dessertMenuItemId] = [
                                    'menu_item_id' => $dessertMenuItemId,
                                    'qty' => $extraDessertQty,
                                    'unit_price' => $this->pricingService->addonPrice('dessert') ?? 0.0,
                                    'label' => 'Dessert Add-on',
                                    'role' => 'dessert',
                                ];
                            }
                        }
                    }
                } else {
                    foreach ($groupItems as $row) {
                        $mealType = (string) ($row['mealType'] ?? '');
                        $bundlePrice = $this->pricingService->bundlePrice($mealType);
                        if ($bundlePrice === null) {
                            throw ValidationException::withMessages([
                                'items' => __("Unsupported mealType '{$mealType}' for {$date}."),
                            ]);
                        }

                        $menuItemId = isset($row['menu_item_id']) ? (int) $row['menu_item_id'] : null;
                        if (! $menuItemId) {
                            $mainName = mb_strtolower(trim((string) ($row['main'] ?? '')));
                            $menuItemId = $mainName !== '' ? ($menuMainMap[$mainName] ?? null) : null;
                        }

                        if (! $menuItemId) {
                            throw ValidationException::withMessages([
                                'items' => __("Could not resolve main item for {$date}."),
                            ]);
                        }

                        $key = $mealType.'|'.$menuItemId;
                        if (! isset($lineGroups[$key])) {
                            $label = match ($mealType) {
                                'full' => 'Full Meal',
                                'mainSalad' => 'Main + Salad',
                                'mainDessert' => 'Main + Dessert',
                                'mainOnly' => 'Main Only',
                                default => $mealType,
                            };
                            $lineGroups[$key] = [
                                'menu_item_id' => $menuItemId,
                                'qty' => 0,
                                'unit_price' => $bundlePrice,
                                'label' => $label,
                                'role' => 'main',
                            ];
                        }
                        $lineGroups[$key]['qty']++;
                    }
                }

                if ($isSubscriptionRequest && $appetizerMenuItemId) {
                    $lineGroups['subscription-appetizer|'.$appetizerMenuItemId] = [
                        'menu_item_id' => $appetizerMenuItemId,
                        'qty' => 1,
                        'unit_price' => 0,
                        'label' => 'Appetizer',
                        'role' => 'appetizer',
                    ];
                }

                $orderTotal = $isSubscriptionRequest ? (float) $planPrice : (float) $websiteDayTotal;

                $order = Order::create([
                    'order_number' => $this->numberService->generate(),
                    'branch_id' => $branchId,
                    'source' => $source,
                    'is_daily_dish' => 1,
                    'type' => 'Delivery',
                    'status' => 'Draft',
                    'customer_id' => $customer->id,
                    'customer_name_snapshot' => $payload['customerName'],
                    'customer_phone_snapshot' => $payload['phone'],
                    'customer_email_snapshot' => $payload['email'] ?? $user->email,
                    'delivery_address_snapshot' => $payload['address'],
                    'scheduled_date' => $date,
                    'scheduled_time' => null,
                    'notes' => $payload['notes'] ?? null,
                    'total_before_tax' => $orderTotal,
                    'tax_amount' => 0,
                    'total_amount' => $orderTotal,
                    'created_by' => $user->id,
                ]);

                $sort = 0;
                foreach (array_values($lineGroups) as $lineGroup) {
                    $menuItem = MenuItem::find($lineGroup['menu_item_id']);
                    $qty = (float) $lineGroup['qty'];
                    $price = (float) $lineGroup['unit_price'];
                    $label = (string) ($lineGroup['label'] ?? 'Daily Dish');

                    OrderItem::create([
                        'order_id' => $order->id,
                        'menu_item_id' => $lineGroup['menu_item_id'],
                        'description_snapshot' => trim('Daily Dish ('.$label.') - '.(($menuItem->code ?? '').' '.($menuItem->name ?? ''))),
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'discount_amount' => 0,
                        'line_total' => round($qty * $price, 3),
                        'status' => 'Pending',
                        'sort_order' => $sort++,
                        'role' => (string) ($lineGroup['role'] ?? 'main'),
                    ]);
                }

                $createdOrderIds[] = $order->id;
            }

            $lead = MealPlanRequest::create([
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'customer_name' => $payload['customerName'],
                'customer_phone' => $payload['phone'],
                'customer_email' => $payload['email'] ?? $user->email,
                'delivery_address' => $payload['address'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'plan_meals' => (int) ($payload['mealPlan'] ?? 0),
                'status' => 'new',
            ]);

            $leadId = $lead->id;

            if ($leadId && $createdOrderIds !== [] && Schema::hasTable('meal_plan_request_orders')) {
                $rows = array_map(fn (int $orderId) => [
                    'meal_plan_request_id' => $leadId,
                    'order_id' => $orderId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $createdOrderIds);

                DB::table('meal_plan_request_orders')->insert($rows);
            }
        });

        $ordersForEmail = Order::query()
            ->with('items')
            ->whereIn('id', $createdOrderIds)
            ->orderBy('scheduled_date')
            ->get();

        $emailSentAdmin = true;
        $emailSentCustomer = true;
        $adminEmail = (string) (env('DAILY_DISH_ADMIN_EMAIL') ?: 'info@layla-kitchen.com');

        try {
            if ($adminEmail !== '') {
                Mail::to($adminEmail)->send(new DailyDishOrderAdminMail(
                    orders: $ordersForEmail,
                    mealPlanMeals: $payload['mealPlan'] ? (int) $payload['mealPlan'] : null,
                    mealPlanRequestId: $leadId
                ));
            }
        } catch (\Throwable) {
            $emailSentAdmin = false;
        }

        try {
            $customerEmail = (string) ($payload['email'] ?? $user->email ?? '');
            if ($customerEmail !== '') {
                Mail::to($customerEmail)->send(new DailyDishOrderCustomerMail(
                    orders: $ordersForEmail,
                    mealPlanMeals: $payload['mealPlan'] ? (int) $payload['mealPlan'] : null,
                    mealPlanRequestId: $leadId
                ));
            }
        } catch (\Throwable) {
            $emailSentCustomer = false;
        }

        return [
            'success' => true,
            'order_ids' => $createdOrderIds,
            'meal_plan_request_id' => $leadId,
            'email_sent_admin' => $emailSentAdmin,
            'email_sent_customer' => $emailSentCustomer,
        ];
    }

    /**
     * @param  array<int, string>  $mainsWithQty
     * @return array<int, array{type:string,main:string,salad:bool,dessert:bool,quantity:int}>
     */
    private function buildBundles(array $mainsWithQty, int $saladQty, int $dessertQty): array
    {
        $mainCount = count($mainsWithQty);
        $remainingSalads = min($saladQty, $mainCount);
        $remainingDesserts = min($dessertQty, $mainCount);
        $bundles = [];
        $mainIndex = 0;

        while ($mainIndex < $mainCount && $remainingSalads > 0 && $remainingDesserts > 0) {
            $bundles[] = [
                'type' => 'full',
                'main' => $mainsWithQty[$mainIndex],
                'salad' => true,
                'dessert' => true,
                'quantity' => 1,
            ];
            $mainIndex++;
            $remainingSalads--;
            $remainingDesserts--;
        }

        while ($mainIndex < $mainCount && $remainingSalads > 0) {
            $bundles[] = [
                'type' => 'mainSalad',
                'main' => $mainsWithQty[$mainIndex],
                'salad' => true,
                'dessert' => false,
                'quantity' => 1,
            ];
            $mainIndex++;
            $remainingSalads--;
        }

        while ($mainIndex < $mainCount && $remainingDesserts > 0) {
            $bundles[] = [
                'type' => 'mainDessert',
                'main' => $mainsWithQty[$mainIndex],
                'salad' => false,
                'dessert' => true,
                'quantity' => 1,
            ];
            $mainIndex++;
            $remainingDesserts--;
        }

        while ($mainIndex < $mainCount) {
            $bundles[] = [
                'type' => 'mainOnly',
                'main' => $mainsWithQty[$mainIndex],
                'salad' => false,
                'dessert' => false,
                'quantity' => 1,
            ];
            $mainIndex++;
        }

        return $bundles;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isSubscriptionRequest(array $payload): bool
    {
        return in_array((string) ($payload['mealPlan'] ?? ''), ['20', '26'], true);
    }

    private function fixedSubscriptionPrice(string $planKey): ?float
    {
        return match ($planKey) {
            '20' => 40.000,
            '26' => 42.300,
            default => null,
        };
    }

    private function resolveWebsiteDayTotal(Collection $items, string $date): float
    {
        $row = $items->first();
        if (! is_array($row) || ! isset($row['day_total']) || ! is_numeric($row['day_total'])) {
            throw ValidationException::withMessages([
                'items' => __("Missing day_total for {$date}."),
            ]);
        }

        return round((float) $row['day_total'], 3);
    }

    private function resolveDefaultAppetizerMenuItemId(): ?int
    {
        $code = trim((string) config('subscriptions.default_appetizer_code', ''));
        if ($code === '') {
            return null;
        }

        $query = MenuItem::query()->where('code', $code);
        if (Schema::hasColumn('menu_items', 'is_active')) {
            $query->where('is_active', 1);
        }

        $item = $query->first(['id']);

        return $item ? (int) $item->id : null;
    }
}

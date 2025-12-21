<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyDishMenu;
use App\Models\MenuItem;
use App\Models\MealPlanRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Mail\DailyDishOrderAdminMail;
use App\Mail\DailyDishOrderCustomerMail;
use App\Services\Orders\OrderNumberService;
use App\Services\Orders\OrderTotalsService;
use App\Services\Security\RecaptchaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PublicDailyDishOrderController extends Controller
{
    private const BUNDLE_PRICES = [
        'full' => 65.0,
        'mainSalad' => 55.0,
        'mainDessert' => 55.0,
        'mainOnly' => 50.0,
    ];
    private const PORTION_PRICES = [
        'plate' => 50.0,
        'half' => 130.0,
        'full' => 200.0,
    ];
    private const ADDON_PRICES = [
        'salad' => 15.0,
        'dessert' => 15.0,
    ];
    private const PLAN_PRICES = [
        '20' => 40.0,
        '26' => 42.30,
    ];
    private const PORTION_LABELS = [
        'plate' => 'Plate',
        'half' => 'Half Portion',
        'full' => 'Full Portion',
    ];

    public function store(
        Request $request,
        RecaptchaService $recaptcha,
        OrderNumberService $numberService,
        OrderTotalsService $totalsService
    ) {
        $payload = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'customerName' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'mealPlan' => ['nullable', 'in:20,26'],
            'recaptcha_token' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.key' => ['required', 'date'],
            'items.*.mains' => ['nullable', 'array'],
            'items.*.mains.*.name' => ['required_with:items.*.mains', 'string'],
            'items.*.mains.*.portion' => ['required_with:items.*.mains', 'in:plate,half,full'],
            'items.*.mains.*.qty' => ['required_with:items.*.mains', 'integer', 'min:1'],
            'items.*.salad' => ['nullable', 'string'],
            'items.*.dessert' => ['nullable', 'string'],
            'items.*.salad_qty' => ['nullable', 'integer', 'min:0'],
            'items.*.dessert_qty' => ['nullable', 'integer', 'min:0'],
            'items.*.mealType' => ['nullable', 'string'],
            'items.*.main' => ['nullable', 'string'],
            'items.*.menu_item_id' => ['nullable', 'integer'],
        ]);

        $verify = $recaptcha->verify($payload['recaptcha_token'] ?? null, $request->ip());
        if (! ($verify['ok'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'reCAPTCHA verification failed.',
                'reason' => $verify['reason'] ?? null,
            ], 422);
        }

        $branchId = (int) ($payload['branch_id'] ?? 1);

        $items = collect($payload['items'] ?? []);
        $usesNewShape = $items->contains(fn ($row) => isset($row['mains']) && is_array($row['mains']));

        // Group into one Order per day (key)
        $groups = $items->groupBy('key');

        $createdOrderIds = [];
        $leadId = null;

        DB::transaction(function () use ($groups, $payload, $branchId, $numberService, $totalsService, &$createdOrderIds, $usesNewShape) {
            foreach ($groups as $date => $items) {
                $menu = DailyDishMenu::query()
                    ->where('branch_id', $branchId)
                    ->whereDate('service_date', $date)
                    ->where('status', 'published')
                    ->with(['items.menuItem'])
                    ->first();

                if (! $menu) {
                    abort(422, "No published daily dish menu for {$date}.");
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

                    foreach ($items as $row) {
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
                                abort(422, "Main dish is required for {$date}.");
                            }

                            if (! isset(self::PORTION_PRICES[$portion])) {
                                abort(422, "Unsupported portion '{$portion}' for {$date}.");
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
                        abort(422, "Please select at least one main dish for {$date}.");
                    }

                    $hasMealPlan = ! empty($payload['mealPlan']);
                    if ($hasMealPlan) {
                        $planKey = (string) $payload['mealPlan'];
                        $planPrice = self::PLAN_PRICES[$planKey] ?? null;
                        if ($planPrice === null) {
                            abort(422, "Unsupported meal plan '{$planKey}'.");
                        }

                        foreach ($mainSelections as $sel) {
                            $menuItemId = $sel['menu_item_id'];
                            if (! $menuItemId) {
                                $mainKey = mb_strtolower(trim((string) $sel['name']));
                                $menuItemId = $mainKey !== '' ? ($menuMainMap[$mainKey] ?? null) : null;
                            }

                            if (! $menuItemId) {
                                abort(422, "Could not resolve main item for {$date}.");
                            }

                            $k = 'plan|'.$menuItemId;
                            if (! isset($lineGroups[$k])) {
                                $lineGroups[$k] = [
                                    'menu_item_id' => $menuItemId,
                                    'qty' => 0,
                                    'unit_price' => $planPrice,
                                    'label' => 'Meal Plan',
                                ];
                            }
                            $lineGroups[$k]['qty'] += $sel['qty'];
                        }
                    } else {
                        $hasNonPlate = false;
                        foreach ($mainSelections as $sel) {
                            if ($sel['portion'] !== 'plate') {
                                $hasNonPlate = true;
                                break;
                            }
                        }

                        if ($hasNonPlate) {
                            foreach ($mainSelections as $sel) {
                                $portion = $sel['portion'];
                                $menuItemId = $sel['menu_item_id'];
                                if (! $menuItemId) {
                                    $mainKey = mb_strtolower(trim((string) $sel['name']));
                                    $menuItemId = $mainKey !== '' ? ($menuMainMap[$mainKey] ?? null) : null;
                                }

                                if (! $menuItemId) {
                                    abort(422, "Could not resolve main item for {$date}.");
                                }

                                $k = $portion.'|'.$menuItemId;
                                if (! isset($lineGroups[$k])) {
                                    $lineGroups[$k] = [
                                        'menu_item_id' => $menuItemId,
                                        'qty' => 0,
                                        'unit_price' => self::PORTION_PRICES[$portion] ?? self::PORTION_PRICES['plate'],
                                        'label' => self::PORTION_LABELS[$portion] ?? 'Plate',
                                    ];
                                }
                                $lineGroups[$k]['qty'] += $sel['qty'];
                            }

                            if ($saladQty > 0) {
                                $saladMenuItemId = $saladMenuItem?->getKey();
                                if (! $saladMenuItemId) {
                                    abort(422, "Could not resolve salad item for {$date}.");
                                }
                                $lineGroups['salad-addon|'.$saladMenuItemId] = [
                                    'menu_item_id' => $saladMenuItemId,
                                    'qty' => $saladQty,
                                    'unit_price' => self::ADDON_PRICES['salad'],
                                    'label' => 'Salad Add-on',
                                ];
                            }

                            if ($dessertQty > 0) {
                                $dessertMenuItemId = $dessertMenuItem?->getKey();
                                if (! $dessertMenuItemId) {
                                    abort(422, "Could not resolve dessert item for {$date}.");
                                }
                                $lineGroups['dessert-addon|'.$dessertMenuItemId] = [
                                    'menu_item_id' => $dessertMenuItemId,
                                    'qty' => $dessertQty,
                                    'unit_price' => self::ADDON_PRICES['dessert'],
                                    'label' => 'Dessert Add-on',
                                ];
                            }
                        } else {
                            $mainsWithQty = [];
                            foreach ($mainSelections as $sel) {
                                for ($i = 0; $i < $sel['qty']; $i++) {
                                    $mainsWithQty[] = $sel['name'];
                                }
                            }

                            $bundles = $this->buildBundles($mainsWithQty, $saladQty, $dessertQty);
                            foreach ($bundles as $bundle) {
                                $mainKey = mb_strtolower(trim((string) $bundle['main']));
                                $menuItemId = $mainKey !== '' ? ($menuMainMap[$mainKey] ?? null) : null;

                                if (! $menuItemId) {
                                    abort(422, "Could not resolve main item for {$date}.");
                                }

                                $bundleType = (string) $bundle['type'];
                                $bundlePrice = self::BUNDLE_PRICES[$bundleType] ?? null;
                                if ($bundlePrice === null) {
                                    abort(422, "Unsupported mealType '{$bundleType}' for {$date}.");
                                }

                                $k = $bundleType.'|'.$menuItemId;
                                if (! isset($lineGroups[$k])) {
                                    $label = match ($bundleType) {
                                        'full' => 'Full Meal',
                                        'mainSalad' => 'Main + Salad',
                                        'mainDessert' => 'Main + Dessert',
                                        'mainOnly' => 'Main Only',
                                        default => $bundleType,
                                    };

                                    $lineGroups[$k] = [
                                        'menu_item_id' => $menuItemId,
                                        'qty' => 0,
                                        'unit_price' => $bundlePrice,
                                        'label' => $label,
                                    ];
                                }
                                $lineGroups[$k]['qty'] += 1;
                            }
                        }
                    }
                } else {
                    foreach ($items as $row) {
                        $mealType = (string) ($row['mealType'] ?? '');
                        $bundlePrice = self::BUNDLE_PRICES[$mealType] ?? null;
                        if ($bundlePrice === null) {
                            abort(422, "Unsupported mealType '{$mealType}' for {$date}.");
                        }

                        $menuItemId = isset($row['menu_item_id']) ? (int) $row['menu_item_id'] : null;
                        if (! $menuItemId) {
                            $mainName = mb_strtolower(trim((string) ($row['main'] ?? '')));
                            $menuItemId = $mainName !== '' ? ($menuMainMap[$mainName] ?? null) : null;
                        }

                        if (! $menuItemId) {
                            abort(422, "Could not resolve main item for {$date}.");
                        }

                        $k = $mealType.'|'.$menuItemId;
                        if (! isset($lineGroups[$k])) {
                            $label = match ($mealType) {
                                'full' => 'Full Meal',
                                'mainSalad' => 'Main + Salad',
                                'mainDessert' => 'Main + Dessert',
                                'mainOnly' => 'Main Only',
                                default => $mealType,
                            };
                            $lineGroups[$k] = [
                                'menu_item_id' => $menuItemId,
                                'qty' => 0,
                                'unit_price' => $bundlePrice,
                                'label' => $label,
                            ];
                        }
                        $lineGroups[$k]['qty']++;
                    }
                }

                $order = Order::create([
                    'order_number' => $numberService->generate(),
                    'branch_id' => $branchId,
                    'source' => 'Website',
                    'is_daily_dish' => 1,
                    'type' => 'Delivery',
                    'status' => 'Draft',
                    'customer_id' => null,
                    'customer_name_snapshot' => $payload['customerName'],
                    'customer_phone_snapshot' => $payload['phone'],
                    'customer_email_snapshot' => $payload['email'] ?? null,
                    'delivery_address_snapshot' => $payload['address'],
                    'scheduled_date' => $date,
                    'scheduled_time' => null,
                    'notes' => $payload['notes'] ?? null,
                    'total_before_tax' => 0,
                    'tax_amount' => 0,
                    'total_amount' => 0,
                    'created_by' => null,
                ]);

                $sort = 0;
                foreach (array_values($lineGroups) as $lg) {
                    $menuItem = MenuItem::find($lg['menu_item_id']);
                    $qty = (float) $lg['qty'];
                    $price = (float) $lg['unit_price'];
                    $label = (string) ($lg['label'] ?? 'Daily Dish');

                    OrderItem::create([
                        'order_id' => $order->id,
                        'menu_item_id' => $lg['menu_item_id'],
                        'description_snapshot' => trim('Daily Dish ('.$label.') - '.(($menuItem->code ?? '').' '.($menuItem->name ?? ''))),
                        'quantity' => $qty,
                        'unit_price' => $price,
                        'discount_amount' => 0,
                        'line_total' => round($qty * $price, 3),
                        'status' => 'Pending',
                        'sort_order' => $sort++,
                    ]);
                }

                $totalsService->recalc($order);

                $createdOrderIds[] = $order->id;
            }
        });

        try {
            $lead = MealPlanRequest::create([
                'customer_name' => $payload['customerName'],
                'customer_phone' => $payload['phone'],
                'customer_email' => $payload['email'] ?? null,
                'delivery_address' => $payload['address'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'plan_meals' => (int) ($payload['mealPlan'] ?? 0),
                'status' => 'new',
                'order_ids' => $createdOrderIds,
            ]);
            $leadId = $lead->id;
        } catch (\Throwable $e) {
            // Don't block orders if lead insert fails.
        }

        $ordersForEmail = Order::query()
            ->with('items')
            ->whereIn('id', $createdOrderIds)
            ->orderBy('scheduled_date')
            ->get();

        $emailSentAdmin = false;
        $emailSentCustomer = false;

        $adminEmail = (string) (env('DAILY_DISH_ADMIN_EMAIL') ?: 'info@layla-kitchen.com');
        try {
            if ($adminEmail !== '') {
                Mail::to($adminEmail)->send(new DailyDishOrderAdminMail(
                    orders: $ordersForEmail,
                    mealPlanMeals: $payload['mealPlan'] ? (int) $payload['mealPlan'] : null,
                    mealPlanRequestId: $leadId
                ));
                $emailSentAdmin = true;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        try {
            if (! empty($payload['email'])) {
                Mail::to($payload['email'])->send(new DailyDishOrderCustomerMail(
                    orders: $ordersForEmail,
                    mealPlanMeals: $payload['mealPlan'] ? (int) $payload['mealPlan'] : null,
                    mealPlanRequestId: $leadId
                ));
                $emailSentCustomer = true;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return response()->json([
            'success' => true,
            'order_ids' => $createdOrderIds,
            'meal_plan_request_id' => $leadId,
            'email_sent_admin' => $emailSentAdmin,
            'email_sent_customer' => $emailSentCustomer,
        ]);
    }

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
}



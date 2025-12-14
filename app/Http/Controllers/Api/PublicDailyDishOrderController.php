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
            'items.*.mealType' => ['required', 'string'],
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

        // Group into one Order per day (key)
        $groups = collect($payload['items'])->groupBy('key');

        $createdOrderIds = [];
        $leadId = null;

        DB::transaction(function () use ($groups, $payload, $branchId, $numberService, $totalsService, &$createdOrderIds) {
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

                // Aggregate same (mealType, menu_item_id) into one line with quantity
                $lineGroups = [];
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
                        $lineGroups[$k] = ['mealType' => $mealType, 'menu_item_id' => $menuItemId, 'qty' => 0, 'unit_price' => $bundlePrice];
                    }
                    $lineGroups[$k]['qty']++;
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

                    $bundleLabel = match ($lg['mealType']) {
                        'full' => 'Full',
                        'mainSalad' => 'Main+Salad',
                        'mainDessert' => 'Main+Dessert',
                        'mainOnly' => 'Main Only',
                        default => $lg['mealType'],
                    };

                    OrderItem::create([
                        'order_id' => $order->id,
                        'menu_item_id' => $lg['menu_item_id'],
                        'description_snapshot' => trim('Daily Dish ('.$bundleLabel.') - '.(($menuItem->code ?? '').' '.($menuItem->name ?? ''))),
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

        if (! empty($payload['mealPlan'])) {
            try {
                $lead = MealPlanRequest::create([
                    'customer_name' => $payload['customerName'],
                    'customer_phone' => $payload['phone'],
                    'customer_email' => $payload['email'] ?? null,
                    'delivery_address' => $payload['address'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'plan_meals' => (int) $payload['mealPlan'],
                    'status' => 'new',
                    'order_ids' => $createdOrderIds,
                ]);
                $leadId = $lead->id;
            } catch (\Throwable $e) {
                // Don't block orders if lead insert fails.
            }
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
}



<?php

use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Order;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Laravel\Fortify\Features;

Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
})->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'active'])
    ->name('dashboard');

Route::middleware(['auth', 'active'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('profile.edit');
    Volt::route('settings/password', 'settings.password')->name('user-password.edit');
    Volt::route('settings/appearance', 'settings.appearance')->name('appearance.edit');
    Volt::route('settings/two-factor', 'settings.two-factor')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                    && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('two-factor.show');
});

Route::middleware(['auth', 'active', 'role:admin', 'ensure.admin'])->group(function () {
    Volt::route('users', 'users.index')->name('users.index');
    Volt::route('users/create', 'users.create')->name('users.create');
    Volt::route('users/{user}/edit', 'users.edit')->name('users.edit');

    Volt::route('categories', 'categories.index')->name('categories.index');
    Volt::route('categories/create', 'categories.create')->name('categories.create');
    Volt::route('categories/{category}/edit', 'categories.edit')->name('categories.edit');

    Volt::route('suppliers', 'suppliers.index')->name('suppliers.index');
    Volt::route('suppliers/create', 'suppliers.create')->name('suppliers.create');
    Volt::route('suppliers/{supplier}/edit', 'suppliers.edit')->name('suppliers.edit');
});

Route::middleware(['auth', 'active', 'role:admin|manager'])->group(function () {
    Volt::route('settings/finance', 'finance.settings')->name('finance.settings');
    Volt::route('settings/payment-terms', 'settings.payment-terms')->name('settings.payment-terms');
    Route::redirect('finance/settings', 'settings/finance');

    Volt::route('ledger/batches', 'ledger.batches.index')->name('ledger.batches.index');
    Volt::route('ledger/batches/{batch}', 'ledger.batches.show')->name('ledger.batches.show');
});

Route::middleware(['auth', 'active', 'role:admin|manager|cashier'])->group(function () {
    Volt::route('customers', 'customers.index')->name('customers.index');
});

Route::middleware(['auth', 'active', 'role:admin|manager'])->group(function () {
    Volt::route('customers/create', 'customers.create')->name('customers.create');
    Volt::route('customers/{customer}/edit', 'customers.edit')->name('customers.edit');
    Volt::route('customers/import', 'customers.import')->name('customers.import');
});

Route::middleware(['auth', 'active', 'role:admin|manager'])->group(function () {
    Volt::route('recipes', 'recipes.index')->name('recipes.index');
    Volt::route('recipes/create', 'recipes.create')->name('recipes.create');
    Volt::route('recipes/{recipe}', 'recipes.show')->name('recipes.show');
    Volt::route('recipes/{recipe}/edit', 'recipes.edit')->name('recipes.edit');
});

Route::middleware(['auth', 'active', 'role:admin|manager|kitchen'])->group(function () {
    Volt::route('daily-dish/menus', 'daily-dish.menus.index')->name('daily-dish.menus.index');
});

Route::middleware(['auth', 'active', 'role:admin|manager'])->group(function () {
    Volt::route('daily-dish/menus/{branch}/{serviceDate}', 'daily-dish.menus.edit')
        ->middleware('ensure.active-branch')
        ->name('daily-dish.menus.edit');
});

Route::middleware(['auth', 'active', 'role:admin|manager'])->group(function () {
    Volt::route('meal-plan-requests', 'meal-plan-requests.index')->name('meal-plan-requests.index');
    Volt::route('meal-plan-requests/{mealPlanRequest}', 'meal-plan-requests.show')->name('meal-plan-requests.show');
});

Route::middleware(['auth', 'active', 'role:admin|manager|kitchen|cashier'])->group(function () {
    Volt::route('daily-dish/ops/{branch}/{date}', 'daily-dish.ops.day')
        ->middleware('ensure.active-branch')
        ->name('daily-dish.ops.day');
    Volt::route('kitchen/ops/{branch}/{date}', 'kitchen.ops')
        ->middleware('ensure.active-branch')
        ->name('kitchen.ops');
});

Route::middleware(['auth', 'active', 'role:admin|manager|cashier'])->group(function () {
    Volt::route('daily-dish/ops/{branch}/{date}/manual/create', 'daily-dish.ops.manual-create')
        ->middleware('ensure.active-branch')
        ->name('daily-dish.ops.manual.create');
});

Route::middleware(['auth', 'active', 'role:admin|manager'])->group(function () {
    Volt::route('subscriptions', 'subscriptions.index')->name('subscriptions.index');
    Volt::route('subscriptions/create', 'subscriptions.create')->name('subscriptions.create');
    Volt::route('subscriptions/{subscription}', 'subscriptions.show')->name('subscriptions.show');
    Volt::route('subscriptions/{subscription}/edit', 'subscriptions.edit')->name('subscriptions.edit');
    Volt::route('subscriptions/generate', 'subscriptions.generate')->name('subscriptions.generate');
});

Route::middleware(['auth', 'active', 'role:admin|manager'])->group(function () {
    Route::get('inventory/items/search', function (Request $request) {
        $term = trim((string) $request->query('q', ''));
        if ($term === '' || strlen($term) < 2) {
            return response()->json([]);
        }

        $prefix = $term.'%';
        $branchId = (int) $request->query('branch_id');
        if (Schema::hasTable('branches') && $branchId <= 0) {
            return response()->json(['message' => __('branch_id is required.')], 422);
        }
        if ($branchId > 0 && Schema::hasTable('branches')) {
            $q = DB::table('branches')->where('id', $branchId);
            if (Schema::hasColumn('branches', 'is_active')) {
                $q->where('is_active', 1);
            }
            if (! $q->exists()) {
                return response()->json(['message' => __('Invalid branch.')], 422);
            }
        }

        $query = InventoryItem::query()
            ->where('status', 'active')
            ->where(function ($q) use ($prefix) {
                $q->where('item_code', 'like', $prefix)
                    ->orWhere('name', 'like', $prefix);
            });

        if ($branchId > 0 && Schema::hasTable('inventory_stocks')) {
            $query->join('inventory_stocks as inv_stock', function ($join) use ($branchId) {
                $join->on('inventory_items.id', '=', 'inv_stock.inventory_item_id')
                    ->where('inv_stock.branch_id', '=', $branchId);
            });
        }

        $items = $query
            ->orderBy('name')
            ->select(['inventory_items.id', 'inventory_items.name', 'inventory_items.item_code'])
            ->limit(15)
            ->get()
            ->map(function (InventoryItem $item) {
                $label = trim(($item->item_code ?? '').' '.$item->name);

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->item_code,
                    'label' => $label,
                ];
            })
            ->values();

        return response()->json($items);
    })->name('inventory.items.search');
});

Route::middleware(['auth', 'active', 'role:admin|manager|kitchen|cashier'])->group(function () {
    Route::get('orders/menu-items/search', function (Request $request) {
        $term = trim((string) $request->query('q', ''));
        $branchId = (int) $request->query('branch_id');
        if ($term === '' || strlen($term) < 2) {
            return response()->json([]);
        }

        if (Schema::hasTable('branches') && $branchId <= 0) {
            return response()->json(['message' => __('branch_id is required.')], 422);
        }
        if ($branchId > 0 && Schema::hasTable('branches')) {
            $q = DB::table('branches')->where('id', $branchId);
            if (Schema::hasColumn('branches', 'is_active')) {
                $q->where('is_active', 1);
            }
            if (! $q->exists()) {
                return response()->json(['message' => __('Invalid branch.')], 422);
            }
        }

        $prefix = $term.'%';
        $items = MenuItem::query()
            ->active()
            ->availableInBranch($branchId)
            ->where(function ($q) use ($prefix) {
                $q->where('code', 'like', $prefix)
                    ->orWhere('name', 'like', $prefix)
                    ->orWhere('arabic_name', 'like', $prefix);
            })
            ->orderBy('name')
            ->select(['id', 'name', 'code', 'selling_price_per_unit'])
            ->limit(12)
            ->get()
            ->map(function (MenuItem $item) {
                $label = trim(($item->code ?? '').' '.$item->name);
                $price = $item->selling_price_per_unit !== null ? (float) $item->selling_price_per_unit : null;

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->code,
                    'label' => $label,
                    'price' => $price,
                    'price_formatted' => $price !== null ? number_format($price, 3, '.', '') : null,
                ];
            })
            ->values();

        return response()->json($items);
    })->name('orders.menu-items.search');

    Route::get('orders/print', function (Request $request) {
        $status = (string) $request->query('status', 'all');
        $source = $request->query('source');
        $branchId = (int) $request->query('branch_id');
        $dailyDishFilter = (string) $request->query('daily_dish_filter', 'all');
        $scheduledDate = $request->query('scheduled_date');
        $search = $request->query('search');

        if ($branchId > 0 && Schema::hasTable('branches')) {
            $q = DB::table('branches')->where('id', $branchId);
            if (Schema::hasColumn('branches', 'is_active')) {
                $q->where('is_active', 1);
            }
            if (! $q->exists()) {
                abort(404);
            }
        }

        $orders = Order::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($source, fn ($q) => $q->where('source', $source))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($dailyDishFilter === 'only', fn ($q) => $q->where('is_daily_dish', 1))
            ->when($dailyDishFilter === 'exclude', function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('is_daily_dish')
                        ->orWhere('is_daily_dish', 0);
                });
            })
            ->when($scheduledDate, fn ($q) => $q->whereDate('scheduled_date', $scheduledDate))
            ->when($search, function ($q) use ($search) {
                $term = '%'.$search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('order_number', 'like', $term)
                        ->orWhere('customer_name_snapshot', 'like', $term)
                        ->orWhere('customer_phone_snapshot', 'like', $term);
                });
            })
            ->when(Schema::hasTable('meal_plan_request_orders'), function ($q) {
                $q->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('meal_plan_request_orders as mpro')
                        ->join('meal_plan_requests as mpr', 'mpr.id', '=', 'mpro.meal_plan_request_id')
                        ->whereColumn('mpro.order_id', 'orders.id')
                        ->whereNotIn('mpr.status', ['converted', 'closed']);
                });
            })
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return view('orders.print', [
            'orders' => $orders,
            'filters' => [
                'status' => $status,
                'source' => $source,
                'branch_id' => $branchId,
                'daily_dish_filter' => $dailyDishFilter,
                'scheduled_date' => $scheduledDate,
                'search' => $search,
            ],
            'generatedAt' => now(),
        ]);
    })->name('orders.print');

    Route::get('orders/print/invoices', function (Request $request) {
        $status = (string) $request->query('status', 'all');
        $source = $request->query('source');
        $branchId = (int) $request->query('branch_id');
        $dailyDishFilter = (string) $request->query('daily_dish_filter', 'all');
        $scheduledDate = $request->query('scheduled_date');
        $search = $request->query('search');

        if ($branchId > 0 && Schema::hasTable('branches')) {
            $q = DB::table('branches')->where('id', $branchId);
            if (Schema::hasColumn('branches', 'is_active')) {
                $q->where('is_active', 1);
            }
            if (! $q->exists()) {
                abort(404);
            }
        }

        $orders = Order::query()
            ->with(['items' => function ($q) {
                $q->orderBy('sort_order')
                    ->orderBy('id')
                    ->select(['id','order_id','description_snapshot','quantity','unit_price','discount_amount','line_total','sort_order']);
            }])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($source, fn ($q) => $q->where('source', $source))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($dailyDishFilter === 'only', fn ($q) => $q->where('is_daily_dish', 1))
            ->when($dailyDishFilter === 'exclude', function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('is_daily_dish')
                        ->orWhere('is_daily_dish', 0);
                });
            })
            ->when($scheduledDate, fn ($q) => $q->whereDate('scheduled_date', $scheduledDate))
            ->when($search, function ($q) use ($search) {
                $term = '%'.$search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('order_number', 'like', $term)
                        ->orWhere('customer_name_snapshot', 'like', $term)
                        ->orWhere('customer_phone_snapshot', 'like', $term);
                });
            })
            ->when(Schema::hasTable('meal_plan_request_orders'), function ($q) {
                $q->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('meal_plan_request_orders as mpro')
                        ->join('meal_plan_requests as mpr', 'mpr.id', '=', 'mpro.meal_plan_request_id')
                        ->whereColumn('mpro.order_id', 'orders.id')
                        ->whereNotIn('mpr.status', ['converted', 'closed']);
                });
            })
            ->orderBy('customer_name_snapshot')
            ->orderBy('order_number')
            ->limit(300)
            ->get();

        return view('orders.invoice-print', [
            'orders' => $orders,
            'filters' => [
                'status' => $status,
                'source' => $source,
                'branch_id' => $branchId,
                'daily_dish_filter' => $dailyDishFilter,
                'scheduled_date' => $scheduledDate,
                'search' => $search,
            ],
            'generatedAt' => now(),
        ]);
    })->name('orders.print.invoices');

    Route::get('orders/kitchen/print', function (Request $request) {
        $date = $request->query('date', now()->toDateString());
        $branchId = (int) $request->query('branch_id');
        $showSubscription = $request->boolean('show_subscription', true);
        $showManual = $request->boolean('show_manual', true);
        $includeDraft = $request->boolean('include_draft', false);
        $includeReady = $request->boolean('include_ready', true);
        $includeCancelled = $request->boolean('include_cancelled', false);
        $includeDelivered = $request->boolean('include_delivered', false);
        $search = $request->query('search');

        if ($branchId > 0 && Schema::hasTable('branches')) {
            $q = DB::table('branches')->where('id', $branchId);
            if (Schema::hasColumn('branches', 'is_active')) {
                $q->where('is_active', 1);
            }
            if (! $q->exists()) {
                abort(404);
            }
        }

        $statuses = ['Confirmed', 'InProduction'];
        if ($includeReady) {
            $statuses[] = 'Ready';
        }
        if ($includeDraft) {
            $statuses[] = 'Draft';
        }
        if ($includeCancelled) {
            $statuses[] = 'Cancelled';
        }
        if ($includeDelivered) {
            $statuses[] = 'Delivered';
        }
        $statuses = array_values(array_unique($statuses));
        if (empty($statuses)) {
            $statuses = ['Confirmed','InProduction','Ready'];
        }

        $orders = Order::query()
            ->select([
                'id','order_number','branch_id','source','is_daily_dish','type','status',
                'customer_name_snapshot','customer_phone_snapshot','delivery_address_snapshot',
                'scheduled_date','scheduled_time','notes','total_amount',
            ])
            ->whereDate('scheduled_date', $date)
            ->whereIn('status', $statuses)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when(! $showSubscription, fn ($q) => $q->where('source', '!=', 'Subscription'))
            ->when(! $showManual, fn ($q) => $q->where('source', 'Subscription'))
            ->when($search, function ($q) use ($search) {
                $term = '%'.$search.'%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('order_number', 'like', $term)
                        ->orWhere('customer_name_snapshot', 'like', $term)
                        ->orWhere('customer_phone_snapshot', 'like', $term);
                });
            })
            ->with(['items' => function ($q) {
                $q->orderBy('sort_order')
                    ->orderBy('id')
                    ->select(['id','order_id','description_snapshot','quantity','status','sort_order']);
            }])
            ->orderBy('status')
            ->orderByRaw('CASE WHEN scheduled_time IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_time')
            ->orderBy('id')
            ->limit(500)
            ->get();

        return view('orders.kitchen-print', [
            'orders' => $orders,
            'filters' => [
                'date' => $date,
                'branch_id' => $branchId,
                'show_subscription' => $showSubscription,
                'show_manual' => $showManual,
                'include_draft' => $includeDraft,
                'include_ready' => $includeReady,
                'include_cancelled' => $includeCancelled,
                'include_delivered' => $includeDelivered,
                'search' => $search,
            ],
            'generatedAt' => now(),
        ]);
    })->name('orders.kitchen.print');

    Volt::route('orders', 'orders.index')->name('orders.index');
    Volt::route('orders/create', 'orders.create')->name('orders.create');
    Volt::route('orders/{order}/edit', 'orders.edit')->name('orders.edit');

    // Deprecated operational routes - redirect to new hubs
    Route::get('orders/daily-dish', function () {
        $branch = (int) request()->integer('branch', 1);
        $date = (string) request()->input('date', now()->toDateString());
        return redirect()->route('daily-dish.ops.day', [$branch, $date]);
    })->name('orders.daily-dish');

    Route::get('orders/kitchen', function () {
        $branch = (int) request()->integer('branch', 1);
        $date = (string) request()->input('date', now()->toDateString());
        return redirect()->route('kitchen.ops', [$branch, $date]);
    })->name('orders.kitchen');

    Route::get('orders/kitchen/cards', function () {
        $branch = (int) request()->integer('branch', 1);
        $date = (string) request()->input('date', now()->toDateString());
        return redirect()->route('kitchen.ops', [$branch, $date]);
    })->name('orders.kitchen.cards');

    Route::get('orders/pastry', function () {
        $branch = (int) request()->integer('branch', 1);
        $date = (string) request()->input('date', now()->toDateString());
        return redirect()->to(route('kitchen.ops', ['branch' => $branch, 'date' => $date, 'department' => 'Pastry']));
    })->name('orders.pastry');

    Route::get('orders/items', function () {
        $branch = (int) request()->integer('branch', 1);
        $date = (string) request()->input('date', now()->toDateString());
        return redirect()->route('kitchen.ops', [$branch, $date]);
    })->name('orders.items');
});

Route::middleware(['auth', 'active', 'role:admin|manager|cashier'])->group(function () {
    Volt::route('inventory', 'inventory.index')->name('inventory.index');
    Volt::route('inventory/{item}', 'inventory.show')->name('inventory.show')->whereNumber('item');
});

Route::middleware(['auth', 'active', 'role:admin|manager'])->group(function () {
    Volt::route('inventory/transfers', 'inventory.transfers')->name('inventory.transfers');
    Volt::route('inventory/create', 'inventory.create')->name('inventory.create');
    Volt::route('inventory/{item}/edit', 'inventory.edit')->name('inventory.edit');
});

Route::middleware(['auth', 'active', 'role:admin|manager|cashier'])->group(function () {
    Volt::route('menu-items', 'menu-items.index')->name('menu-items.index');
});

Route::middleware(['auth', 'active', 'role:admin|manager'])->group(function () {
    Volt::route('menu-items/availability', 'menu-items.availability')->name('menu-items.availability');
    Volt::route('menu-items/create', 'menu-items.create')->name('menu-items.create');
    Volt::route('menu-items/{menuItem}/edit', 'menu-items.edit')->name('menu-items.edit');

    Volt::route('payables', 'payables.index')->name('payables.index');
    Volt::route('payables/invoices/create', 'payables.invoices.create')->name('payables.invoices.create');
    Volt::route('payables/invoices/{invoice}', 'payables.invoices.show')->name('payables.invoices.show');
    Volt::route('payables/invoices/{invoice}/edit', 'payables.invoices.edit')->name('payables.invoices.edit');
    Volt::route('payables/payments/create', 'payables.payments.create')->name('payables.payments.create');
    Volt::route('payables/payments/{payment}', 'payables.payments.show')->name('payables.payments.show');

    Volt::route('purchase-orders', 'purchase-orders.index')->name('purchase-orders.index');
    Volt::route('purchase-orders/create', 'purchase-orders.create')->name('purchase-orders.create');
    Volt::route('purchase-orders/{purchaseOrder}', 'purchase-orders.show')->name('purchase-orders.show');
    Volt::route('purchase-orders/{purchaseOrder}/edit', 'purchase-orders.edit')->name('purchase-orders.edit');

    // Expenses
    Volt::route('expenses', 'expenses.index')->name('expenses.index');
    Volt::route('expenses/create', 'expenses.create')->name('expenses.create');
    // Categories (place before parameterized expense routes to avoid collisions)
    Volt::route('expenses/categories', 'expenses.categories')->name('expenses.categories');
    Volt::route('expenses/{expense}', 'expenses.show')->name('expenses.show');
    Volt::route('expenses/{expense}/edit', 'expenses.edit')->name('expenses.edit');
});

Route::middleware(['auth', 'active', 'role:admin|manager|staff'])->group(function () {
    // Use the richer petty cash Volt page component
    Volt::route('petty-cash', 'petty-cash.page')->name('petty-cash.index');
});

// POS (front-of-house) disabled on web
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('pos', fn () => abort(404))->name('pos.index');
    Route::get('pos/shift', fn () => abort(404))->name('pos.shift');
    Route::get('pos/branch', fn () => abort(404))->name('pos.branch-select');
});

// Sales tickets (POS receipts)
Route::middleware(['auth', 'active', 'role:admin|manager|cashier'])->group(function () {
    Volt::route('sales', 'sales.index')->name('sales.index');
    Volt::route('sales/{sale}', 'sales.show')->name('sales.show');
    Route::get('sales/{sale}/receipt', function (\App\Models\Sale $sale) {
        $sale->load(['items', 'paymentAllocations.payment']);
        return view('sales.receipt', ['sale' => $sale]);
    })->name('sales.receipt');
    Route::get('sales/{sale}/kot', function (\App\Models\Sale $sale) {
        $sale->load(['items']);
        return view('sales.kot', ['sale' => $sale]);
    })->name('sales.kot');
});

// AR (receivables)
Route::middleware(['auth', 'active', 'role:admin|manager'])->group(function () {
    Volt::route('invoices', 'receivables.invoices.index')->name('invoices.index');
    Volt::route('invoices/create', 'receivables.invoices.create')->name('invoices.create');
    Volt::route('invoices/{invoice}', 'receivables.invoices.show')->name('invoices.show');
    Route::get('invoices/{invoice}/print', function (\App\Models\ArInvoice $invoice) {
        $invoice->load(['items', 'customer', 'paymentAllocations.payment']);
        return view('receivables.invoice-print', ['invoice' => $invoice]);
    })->name('invoices.print');
});

// Reports (manager/staff)
Route::middleware(['auth', 'active', 'role:admin|manager|staff'])->prefix('reports')->name('reports.')->group(function () {
    Volt::route('/', 'reports.index')->name('index');
    Volt::route('orders', 'reports.orders')->name('orders');
    Volt::route('purchase-orders', 'reports.purchase-orders')->name('purchase-orders');
    Volt::route('expenses', 'reports.expenses')->name('expenses');
    Volt::route('costing', 'reports.costing')->name('costing');
    Volt::route('sales', 'reports.sales')->name('sales');
    Volt::route('inventory', 'reports.inventory')->name('inventory');
    Volt::route('payables', 'reports.payables')->name('payables');
    Volt::route('receivables', 'reports.receivables')->name('receivables');

    Route::get('orders/print', [\App\Http\Controllers\Reports\OrdersReportController::class, 'print'])->name('orders.print');
    Route::get('orders/csv', [\App\Http\Controllers\Reports\OrdersReportController::class, 'csv'])->name('orders.csv');
    Route::get('orders/pdf', [\App\Http\Controllers\Reports\OrdersReportController::class, 'pdf'])->name('orders.pdf');
    Route::get('purchase-orders/print', [\App\Http\Controllers\Reports\PurchaseOrdersReportController::class, 'print'])->name('purchase-orders.print');
    Route::get('purchase-orders/csv', [\App\Http\Controllers\Reports\PurchaseOrdersReportController::class, 'csv'])->name('purchase-orders.csv');
    Route::get('purchase-orders/pdf', [\App\Http\Controllers\Reports\PurchaseOrdersReportController::class, 'pdf'])->name('purchase-orders.pdf');
    Route::get('expenses/print', [\App\Http\Controllers\Reports\ExpensesReportController::class, 'print'])->name('expenses.print');
    Route::get('expenses/csv', [\App\Http\Controllers\Reports\ExpensesReportController::class, 'csv'])->name('expenses.csv');
    Route::get('expenses/pdf', [\App\Http\Controllers\Reports\ExpensesReportController::class, 'pdf'])->name('expenses.pdf');
    Route::get('costing/print', [\App\Http\Controllers\Reports\CostingReportController::class, 'print'])->name('costing.print');
    Route::get('costing/csv', [\App\Http\Controllers\Reports\CostingReportController::class, 'csv'])->name('costing.csv');
    Route::get('costing/pdf', [\App\Http\Controllers\Reports\CostingReportController::class, 'pdf'])->name('costing.pdf');
    Route::get('sales/print', [\App\Http\Controllers\Reports\SalesReportController::class, 'print'])->name('sales.print');
    Route::get('sales/csv', [\App\Http\Controllers\Reports\SalesReportController::class, 'csv'])->name('sales.csv');
    Route::get('sales/pdf', [\App\Http\Controllers\Reports\SalesReportController::class, 'pdf'])->name('sales.pdf');
    Route::get('inventory/print', [\App\Http\Controllers\Reports\InventoryReportController::class, 'print'])->name('inventory.print');
    Route::get('inventory/csv', [\App\Http\Controllers\Reports\InventoryReportController::class, 'csv'])->name('inventory.csv');
    Route::get('inventory/pdf', [\App\Http\Controllers\Reports\InventoryReportController::class, 'pdf'])->name('inventory.pdf');
    Route::get('payables/print', [\App\Http\Controllers\Reports\PayablesReportController::class, 'print'])->name('payables.print');
    Route::get('payables/csv', [\App\Http\Controllers\Reports\PayablesReportController::class, 'csv'])->name('payables.csv');
    Route::get('payables/pdf', [\App\Http\Controllers\Reports\PayablesReportController::class, 'pdf'])->name('payables.pdf');
    Route::get('receivables/print', [\App\Http\Controllers\Reports\ReceivablesReportController::class, 'print'])->name('receivables.print');
    Route::get('receivables/csv', [\App\Http\Controllers\Reports\ReceivablesReportController::class, 'csv'])->name('receivables.csv');
    Route::get('receivables/pdf', [\App\Http\Controllers\Reports\ReceivablesReportController::class, 'pdf'])->name('receivables.pdf');
});

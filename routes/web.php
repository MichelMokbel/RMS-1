<?php

use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Laravel\Fortify\Features;

Route::get('/', function () {
    if (Auth::check()) {
        /** @var User $user */
        $user = Auth::user();
        if ($user->hasRole('admin')) {
            return redirect()->route('dashboard');
        }
        if ($user->hasAnyRole(['manager', 'kitchen', 'cashier']) || $user->can('orders.access') || $user->can('operations.access')) {
            return redirect()->route('orders.index');
        }
        if ($user->hasAnyRole(['staff']) || $user->can('reports.access')) {
            return redirect()->route('reports.index');
        }

        return redirect()->route('profile.edit');
    }

    return redirect()->route('login');
})->name('home');

// Temporary utility endpoint for importing menu_items.arabic_name from CSV in environments
// where CLI access is unavailable. Protect via env flag + token (+ optional expiry).
Route::get('/tools/menu-items/import-arabic-names', function (Request $request) {
    $enabled = filter_var((string) env('TEMP_ARABIC_IMPORT_ENABLED', false), FILTER_VALIDATE_BOOL);
    if (! $enabled) {
        abort(404);
    }

    $expectedToken = (string) env('TEMP_ARABIC_IMPORT_TOKEN', '');
    $providedToken = (string) $request->query('token', '');
    if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
        abort(403, 'Invalid token.');
    }

    $expiresAt = trim((string) env('TEMP_ARABIC_IMPORT_EXPIRES_AT', ''));
    if ($expiresAt !== '') {
        $ts = strtotime($expiresAt);
        if ($ts === false) {
            abort(500, 'Invalid TEMP_ARABIC_IMPORT_EXPIRES_AT format.');
        }
        if (time() > $ts) {
            abort(403, 'Import URL expired.');
        }
    }

    $csvPath = (string) env('TEMP_ARABIC_IMPORT_FILE', base_path('arabic-names.csv'));
    if (! Str::startsWith($csvPath, ['/'])) {
        $csvPath = base_path($csvPath);
    }
    if (! is_file($csvPath)) {
        abort(404, "CSV file not found at: {$csvPath}");
    }

    if (! Schema::hasTable('menu_items') || ! Schema::hasColumn('menu_items', 'arabic_name')) {
        abort(500, 'menu_items.arabic_name is not available.');
    }

    $fh = fopen($csvPath, 'r');
    if ($fh === false) {
        abort(500, "Unable to open CSV: {$csvPath}");
    }

    $header = fgetcsv($fh, 0, ',', '"', '\\');
    if ($header === false) {
        fclose($fh);
        abort(422, 'CSV is empty.');
    }

    $header = array_map(fn ($v) => strtolower(trim((string) $v)), $header);
    $idIdx = array_search('id', $header, true);
    $arabicIdx = array_search('arabic_name', $header, true);
    if ($idIdx === false || $arabicIdx === false) {
        fclose($fh);
        abort(422, 'CSV must include headers: id, arabic_name');
    }

    $processed = 0;
    $updated = 0;
    $skipped = 0;
    $notFound = 0;

    DB::beginTransaction();
    try {
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $processed++;
            $id = isset($row[$idIdx]) ? (int) trim((string) $row[$idIdx]) : 0;
            $arabic = isset($row[$arabicIdx]) ? trim((string) $row[$arabicIdx]) : '';

            if ($id <= 0 || $arabic === '') {
                $skipped++;
                continue;
            }

            $exists = DB::table('menu_items')->where('id', $id)->exists();
            if (! $exists) {
                $notFound++;
                continue;
            }

            $affected = DB::table('menu_items')
                ->where('id', $id)
                ->update([
                    'arabic_name' => $arabic,
                    'updated_at' => now(),
                ]);

            if ($affected > 0) {
                $updated++;
            }
        }

        DB::commit();
    } catch (\Throwable $e) {
        DB::rollBack();
        fclose($fh);
        throw $e;
    }

    fclose($fh);

    return response()->json([
        'status' => 'ok',
        'message' => 'Arabic names import completed.',
        'file' => $csvPath,
        'processed' => $processed,
        'updated' => $updated,
        'skipped_invalid_or_empty' => $skipped,
        'not_found_ids' => $notFound,
    ]);
});

Volt::route('dashboard', 'dashboard')
    ->middleware(['auth', 'active', 'role:admin', 'ensure.admin'])
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
    Volt::route('iam/users', 'users.index')->name('iam.users.index');
    Volt::route('iam/users/create', 'users.create')->name('iam.users.create');
    Volt::route('iam/users/{user}/edit', 'users.edit')->name('iam.users.edit');
    Volt::route('iam/roles', 'users.roles')->name('iam.roles.index');
    Volt::route('iam/permissions', 'users.permissions')->name('iam.permissions.index');

    Route::redirect('users', 'iam/users')->name('users.index');
    Route::redirect('users/create', 'iam/users/create')->name('users.create');
    Route::get('users/{user}/edit', function (\App\Models\User $user) {
        return redirect()->route('iam.users.edit', $user);
    })->name('users.edit');

    Volt::route('categories', 'categories.index')->name('categories.index');
    Volt::route('categories/create', 'categories.create')->name('categories.create');
    Volt::route('categories/{category}/edit', 'categories.edit')->name('categories.edit');

    Volt::route('suppliers', 'suppliers.index')->name('suppliers.index');
    Volt::route('suppliers/create', 'suppliers.create')->name('suppliers.create');
    Volt::route('suppliers/{supplier}/edit', 'suppliers.edit')->name('suppliers.edit');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|finance.access|settings.pos_terminals.manage'])->group(function () {
    Volt::route('settings/finance', 'finance.settings')->name('finance.settings');
    Volt::route('settings/accounting', 'settings.accounting')->name('settings.accounting');
    Volt::route('settings/payment-terms', 'settings.payment-terms')->name('settings.payment-terms');
    Volt::route('settings/pos-terminals', 'settings.pos-terminals')->name('settings.pos-terminals');
    Route::redirect('finance/settings', 'settings/finance');

    Volt::route('ledger/batches', 'ledger.batches.index')->name('ledger.batches.index');
    Volt::route('ledger/batches/{batch}', 'ledger.batches.show')->name('ledger.batches.show');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|finance.access'])->group(function () {
    Volt::route('settings/organization', 'settings.organization')->name('settings.organization');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|cashier|receivables.access'])->group(function () {
    Volt::route('customers', 'customers.index')->name('customers.index');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|receivables.access'])->group(function () {
    Volt::route('customers/create', 'customers.create')->name('customers.create');
    Volt::route('customers/{customer}/edit', 'customers.edit')->name('customers.edit');
    Volt::route('customers/import', 'customers.import')->name('customers.import');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|catalog.access'])->group(function () {
    Volt::route('recipes', 'recipes.index')->name('recipes.index');
    Volt::route('recipes/create', 'recipes.create')->name('recipes.create');
    Volt::route('recipes/{recipe}', 'recipes.show')->name('recipes.show');
    Volt::route('recipes/{recipe}/edit', 'recipes.edit')->name('recipes.edit');

    Route::get('recipes/menu-items/search', function (Request $request) {
        $term = trim((string) $request->query('q', ''));
        if ($term === '' || strlen($term) < 2) {
            return response()->json([]);
        }

        $prefix = $term.'%';
        $items = MenuItem::query()
            ->active()
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
                $price = $item->selling_price_per_unit !== null ? (float) $item->selling_price_per_unit : null;

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->code,
                    'label' => $item->name,
                    'price' => $price,
                    'price_formatted' => $price !== null ? number_format($price, 3, '.', '') : null,
                ];
            })
            ->values();

        return response()->json($items);
    })->name('recipes.menu-items.search');

    Route::get('recipes/inventory-items/search', function (Request $request) {
        $term = trim((string) $request->query('q', ''));
        if ($term === '' || strlen($term) < 2) {
            return response()->json([]);
        }

        $prefix = $term.'%';
        $items = InventoryItem::query()
            ->where('status', 'active')
            ->where(function ($q) use ($prefix) {
                $q->where('item_code', 'like', $prefix)
                    ->orWhere('name', 'like', $prefix);
            })
            ->orderBy('name')
            ->select(['id', 'name', 'item_code', 'unit_of_measure'])
            ->limit(12)
            ->get()
            ->map(function (InventoryItem $item) {
                $label = trim((string) ($item->item_code ?? '').' '.(string) $item->name);

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->item_code,
                    'unit' => $item->unit_of_measure,
                    'label' => $label !== '' ? $label : (string) $item->name,
                ];
            })
            ->values();

        return response()->json($items);
    })->name('recipes.inventory-items.search');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|kitchen|operations.access'])->group(function () {
    Volt::route('daily-dish/menus', 'daily-dish.menus.index')->name('daily-dish.menus.index');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|operations.access'])->group(function () {
    Volt::route('daily-dish/menus/{branch}/{serviceDate}', 'daily-dish.menus.edit')
        ->middleware('ensure.active-branch')
        ->name('daily-dish.menus.edit');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|operations.access'])->group(function () {
    Volt::route('meal-plan-requests', 'meal-plan-requests.index')->name('meal-plan-requests.index');
    Volt::route('meal-plan-requests/{mealPlanRequest}', 'meal-plan-requests.show')->name('meal-plan-requests.show');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|kitchen|cashier|operations.access'])->group(function () {
    Volt::route('daily-dish/ops/{branch}/{date}', 'daily-dish.ops.day')
        ->middleware('ensure.active-branch')
        ->name('daily-dish.ops.day');
    Volt::route('kitchen/ops/{branch}/{date}', 'kitchen.ops')
        ->middleware('ensure.active-branch')
        ->name('kitchen.ops');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|cashier|operations.access'])->group(function () {
    Volt::route('daily-dish/ops/{branch}/{date}/manual/create', 'daily-dish.ops.manual-create')
        ->middleware('ensure.active-branch')
        ->name('daily-dish.ops.manual.create');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|operations.access'])->group(function () {
    Volt::route('subscriptions', 'subscriptions.index')->name('subscriptions.index');
    Volt::route('subscriptions/create', 'subscriptions.create')->name('subscriptions.create');
    Volt::route('subscriptions/{subscription}', 'subscriptions.show')->name('subscriptions.show');
    Volt::route('subscriptions/{subscription}/edit', 'subscriptions.edit')->name('subscriptions.edit');
    Volt::route('subscriptions/generate', 'subscriptions.generate')->name('subscriptions.generate');
});

// Company Food (standalone module)
$buildCompanyFoodEmployeeOrdersReportData = function (Request $request, \App\Models\CompanyFoodProject $project): array {
    $project->load([
        'employeeLists' => fn ($q) => $q->with([
            'employees' => fn ($qq) => $qq->orderBy('sort_order')->orderBy('employee_name'),
        ])->orderBy('sort_order'),
    ]);

    $ordersQuery = \App\Models\CompanyFoodOrder::query()
        ->where('project_id', $project->id)
        ->with(['employeeList', 'saladOption', 'appetizerOption1', 'appetizerOption2', 'mainOption', 'sweetOption', 'locationOption', 'soupOption'])
        ->orderBy('employee_name')
        ->orderBy('order_date');

    if ($request->filled('order_date')) {
        $ordersQuery->whereDate('order_date', (string) $request->query('order_date'));
    }

    $employeeListId = (int) $request->query('employee_list_id');
    if ($employeeListId > 0) {
        $ordersQuery->where('employee_list_id', $employeeListId);
    }

    $locationOptionId = (int) $request->query('location_option_id');
    if ($locationOptionId > 0) {
        $ordersQuery->where('location_option_id', $locationOptionId);
    }

    $orders = $ordersQuery->get();

    $employeeSortOrderByKey = \App\Models\CompanyFoodEmployee::query()
        ->where('project_id', $project->id)
        ->get()
        ->mapWithKeys(function (\App\Models\CompanyFoodEmployee $employee): array {
            return [
                $employee->employee_list_id.'|'.mb_strtolower(trim((string) $employee->employee_name)) => (int) $employee->sort_order,
            ];
        });

    $orders->transform(function (\App\Models\CompanyFoodOrder $order) use ($employeeSortOrderByKey): \App\Models\CompanyFoodOrder {
        $lookupKey = $order->employee_list_id.'|'.mb_strtolower(trim((string) $order->employee_name));
        $order->employee_sort_order = $employeeSortOrderByKey->get($lookupKey);

        return $order;
    });

    $listSortOrderById = $project->employeeLists
        ->mapWithKeys(fn (\App\Models\CompanyFoodEmployeeList $list): array => [(int) $list->id => (int) $list->sort_order])
        ->all();

    $orders = $orders
        ->sortBy(function (\App\Models\CompanyFoodOrder $order) use ($listSortOrderById): string {
            $date = $order->order_date?->format('Y-m-d') ?? '9999-12-31';
            $listSort = (int) ($listSortOrderById[(int) $order->employee_list_id] ?? 999999);
            $employeeSort = (int) ($order->employee_sort_order ?? 999999);
            $name = mb_strtolower(trim((string) $order->employee_name));

            return $date.'|'.str_pad((string) $listSort, 6, '0', STR_PAD_LEFT).'|'.str_pad((string) $employeeSort, 6, '0', STR_PAD_LEFT).'|'.$name.'|'.str_pad((string) $order->id, 12, '0', STR_PAD_LEFT);
        })
        ->values();

    return [
        'project' => $project,
        'orders' => $orders,
        'filters' => [
            'order_date' => $request->query('order_date'),
            'employee_list_id' => $employeeListId > 0 ? $employeeListId : null,
            'location_option_id' => $locationOptionId > 0 ? $locationOptionId : null,
        ],
        'generatedAt' => now(),
    ];
};

$buildCompanyFoodKitchenPrepReportData = function (Request $request, \App\Models\CompanyFoodProject $project): array {
    $project->load([
        'options' => fn ($q) => $q->orderBy('menu_date')->orderBy('category')->orderBy('sort_order'),
        'employeeLists' => fn ($q) => $q->with([
            'listCategories' => fn ($qq) => $qq->orderBy('sort_order'),
        ])->orderBy('sort_order'),
    ]);

    $ordersQuery = \App\Models\CompanyFoodOrder::query()
        ->where('project_id', $project->id)
        ->with(['employeeList', 'saladOption', 'appetizerOption1', 'appetizerOption2', 'mainOption', 'sweetOption', 'locationOption', 'soupOption'])
        ->orderBy('order_date')
        ->orderBy('employee_name');

    if ($request->filled('order_date')) {
        $ordersQuery->whereDate('order_date', (string) $request->query('order_date'));
    }

    $employeeListId = (int) $request->query('employee_list_id');
    if ($employeeListId > 0) {
        $ordersQuery->where('employee_list_id', $employeeListId);
    }

    $locationOptionId = (int) $request->query('location_option_id');
    if ($locationOptionId > 0) {
        $ordersQuery->where('location_option_id', $locationOptionId);
    }

    $orders = $ordersQuery->get();

    $employeeListsById = $project->employeeLists->keyBy('id');
    $employeeListIdsOrdered = $project->employeeLists->pluck('id')->map(fn ($id) => (int) $id)->all();
    $employeeListOrderLookup = array_fill_keys($employeeListIdsOrdered, true);
    $optionNamesById = $project->options->pluck('name', 'id');

    $kitchenPrep = $orders->groupBy(fn ($o) => $o->order_date?->format('Y-m-d'))->map(function ($dateOrders) use ($employeeListsById, $employeeListIdsOrdered, $employeeListOrderLookup, $optionNamesById) {
        $groupedByList = $dateOrders->groupBy('employee_list_id');
        $orderedByList = collect();

        foreach ($employeeListIdsOrdered as $listId) {
            if ($groupedByList->has($listId)) {
                $orderedByList->put((string) $listId, $groupedByList->get($listId));
            }
        }

        foreach ($groupedByList as $listId => $listOrders) {
            if (! isset($employeeListOrderLookup[(int) $listId])) {
                $orderedByList->put((string) $listId, $listOrders);
            }
        }

        return $orderedByList->map(function ($listOrders, $listId) use ($employeeListsById, $optionNamesById) {
            $list = $employeeListsById->get((int) $listId);
            $categories = $list
                ? $list->listCategories->pluck('category')->values()->all()
                : \App\Models\CompanyFoodOption::CATEGORIES;

            $sections = $listOrders->groupBy('location_option_id')->map(function ($locOrders, $locId) use ($optionNamesById) {
                $locationName = $locOrders->first()?->locationOption?->name ?? ($locId ? __('Unknown') : __('No location'));

                $counts = [
                    'salad' => $locOrders->groupBy('salad_option_id')->map->count(),
                    'appetizer' => $locOrders->flatMap(fn ($o) => array_filter([$o->appetizer_option_id_1, $o->appetizer_option_id_2]))
                        ->groupBy(fn ($id) => $id)->map->count(),
                    'main' => $locOrders->groupBy('main_option_id')->map->count(),
                    'sweet' => $locOrders->groupBy('sweet_option_id')->map->count(),
                    'soup' => $locOrders->groupBy('soup_option_id')->map->count(),
                ];

                $resolvedCounts = collect($counts)->map(function ($categoryCounts) use ($optionNamesById) {
                    return $categoryCounts->filter(fn ($_, $id) => (bool) $id)
                        ->map(function ($count, $id) use ($optionNamesById) {
                            return [
                                'name' => $optionNamesById->get((int) $id, __('Option #:id', ['id' => $id])),
                                'count' => $count,
                            ];
                        })
                        ->values()
                        ->all();
                })->all();

                return [
                    'name' => $locationName,
                    'counts' => $resolvedCounts,
                ];
            })->values()->all();

            return [
                'listName' => $list?->name ?? __('Unknown'),
                'sections' => $sections,
                'categories' => $categories,
            ];
        })->values()->all();
    })->all();

    return [
        'project' => $project,
        'kitchenPrep' => $kitchenPrep,
        'filters' => [
            'order_date' => $request->query('order_date'),
            'employee_list_id' => $employeeListId > 0 ? $employeeListId : null,
            'location_option_id' => $locationOptionId > 0 ? $locationOptionId : null,
        ],
        'generatedAt' => now(),
    ];
};

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|operations.access'])->prefix('company-food')->name('company-food.')->group(function () use ($buildCompanyFoodEmployeeOrdersReportData, $buildCompanyFoodKitchenPrepReportData) {
    Volt::route('projects', 'company-food.projects.index')->name('projects.index');
    Volt::route('projects/create', 'company-food.projects.create')->name('projects.create');
    Route::get('projects/{project}/export-employees-pdf', function (Request $request, \App\Models\CompanyFoodProject $project) use ($buildCompanyFoodEmployeeOrdersReportData) {
        $reportData = $buildCompanyFoodEmployeeOrdersReportData($request, $project);

        return \App\Support\Reports\PdfExport::download(
            'reports.company-food-employees-print',
            $reportData,
            'company-food-employees-' . $project->slug . '.pdf',
            'a4',
            'landscape'
        );
    })->name('projects.export-employees-pdf');
    Route::get('projects/{project}/print-employee-orders', function (Request $request, \App\Models\CompanyFoodProject $project) use ($buildCompanyFoodEmployeeOrdersReportData) {
        $reportData = $buildCompanyFoodEmployeeOrdersReportData($request, $project);

        return view('reports.company-food-employees-print', array_merge($reportData, [
            'browserPrint' => true,
        ]));
    })->name('projects.print-employee-orders');
    Route::get('projects/{project}/export-kitchen-prep-pdf', function (Request $request, \App\Models\CompanyFoodProject $project) use ($buildCompanyFoodKitchenPrepReportData) {
        $reportData = $buildCompanyFoodKitchenPrepReportData($request, $project);

        return \App\Support\Reports\PdfExport::download(
            'reports.company-food-kitchen-prep-print',
            $reportData,
            'company-food-kitchen-prep-' . $project->slug . '.pdf',
            'a4',
            'portrait'
        );
    })->name('projects.export-kitchen-prep-pdf');
    Route::get('projects/{project}/print-kitchen-prep', function (Request $request, \App\Models\CompanyFoodProject $project) use ($buildCompanyFoodKitchenPrepReportData) {
        $reportData = $buildCompanyFoodKitchenPrepReportData($request, $project);

        return view('reports.company-food-kitchen-prep-print', array_merge($reportData, [
            'browserPrint' => true,
        ]));
    })->name('projects.print-kitchen-prep');
    Route::get('projects/{project}/export-csv', function (Request $request, \App\Models\CompanyFoodProject $project) use ($buildCompanyFoodEmployeeOrdersReportData) {
        $reportData = $buildCompanyFoodEmployeeOrdersReportData($request, $project);
        $orders = $reportData['orders'];

        return response()->streamDownload(function () use ($orders) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'List', 'Employee', 'Salad', 'Appetizer 1', 'Appetizer 2', 'Main', 'Sweet', 'Soup', 'Location']);
            foreach ($orders as $o) {
                fputcsv($out, [
                    $o->order_date?->format('Y-m-d') ?? '',
                    $o->employeeList?->name ?? '',
                    $o->employee_name,
                    $o->saladOption?->name ?? '',
                    $o->appetizerOption1?->name ?? '',
                    $o->appetizerOption2?->name ?? '',
                    $o->mainOption?->name ?? '',
                    $o->sweetOption?->name ?? '',
                    $o->soupOption?->name ?? '',
                    $o->locationOption?->name ?? '',
                ]);
            }
            fclose($out);
        }, 'company-food-orders-' . $project->slug . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    })->name('projects.export-csv');
    Volt::route('projects/{project}', 'company-food.projects.show')->name('projects.show');
    Volt::route('projects/{project}/edit', 'company-food.projects.edit')->name('projects.edit');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|operations.access'])->group(function () {
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

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|kitchen|cashier|orders.access|operations.access'])->group(function () {
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
                $price = $item->selling_price_per_unit !== null ? (float) $item->selling_price_per_unit : null;

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->code,
                    'label' => $item->name,
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
        $isAdmin = $request->user()?->isAdmin() ?? false;
        $allowedBranchIds = collect($request->attributes->get('allowed_branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
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
        if (! $isAdmin && $branchId > 0 && ! in_array($branchId, $allowedBranchIds, true)) {
            abort(403);
        }
        if (! $isAdmin && $branchId <= 0 && $allowedBranchIds === []) {
            abort(403);
        }

        $orders = Order::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($source, fn ($q) => $q->where('source', $source))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when(! $isAdmin && $branchId <= 0, fn ($q) => $q->whereIn('branch_id', $allowedBranchIds))
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
        $isAdmin = $request->user()?->isAdmin() ?? false;
        $allowedBranchIds = collect($request->attributes->get('allowed_branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
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
        if (! $isAdmin && $branchId > 0 && ! in_array($branchId, $allowedBranchIds, true)) {
            abort(403);
        }
        if (! $isAdmin && $branchId <= 0 && $allowedBranchIds === []) {
            abort(403);
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
            ->when(! $isAdmin && $branchId <= 0, fn ($q) => $q->whereIn('branch_id', $allowedBranchIds))
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
        $isAdmin = $request->user()?->isAdmin() ?? false;
        $allowedBranchIds = collect($request->attributes->get('allowed_branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
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
        if (! $isAdmin && $branchId > 0 && ! in_array($branchId, $allowedBranchIds, true)) {
            abort(403);
        }
        if (! $isAdmin && $branchId <= 0 && $allowedBranchIds === []) {
            abort(403);
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
            ->when(! $isAdmin && $branchId <= 0, fn ($q) => $q->whereIn('branch_id', $allowedBranchIds))
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

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|cashier|operations.access'])->group(function () {
    Volt::route('inventory', 'inventory.index')->name('inventory.index');
    Volt::route('inventory/{item}', 'inventory.show')->name('inventory.show')->whereNumber('item');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|operations.access'])->group(function () {
    Volt::route('inventory/transfers', 'inventory.transfers')->name('inventory.transfers');
    Volt::route('inventory/create', 'inventory.create')->name('inventory.create');
    Volt::route('inventory/{item}/edit', 'inventory.edit')->name('inventory.edit');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|cashier|catalog.access'])->group(function () {
    Volt::route('menu-items', 'menu-items.index')->name('menu-items.index');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|catalog.access|finance.access|operations.access'])->group(function () {
    Route::get('purchase-orders/suppliers/search', function (Request $request) {
        if (! Schema::hasTable('suppliers')) {
            return response()->json([]);
        }

        $term = trim((string) $request->query('q', ''));
        if ($term === '' || mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $like = '%'.$term.'%';
        $query = Supplier::query()
            ->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('contact_person', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });

        if (Schema::hasColumn('suppliers', 'status')) {
            $query->where('status', 'active');
        }

        $suppliers = $query
            ->orderBy('name')
            ->select(['id', 'name', 'email', 'phone'])
            ->limit(20)
            ->get()
            ->map(function (Supplier $supplier) {
                $label = trim((string) $supplier->name);
                if (! empty($supplier->email)) {
                    $label .= ' ('.$supplier->email.')';
                }

                return [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'email' => $supplier->email,
                    'phone' => $supplier->phone,
                    'label' => $label,
                ];
            })
            ->values();

        return response()->json($suppliers);
    })->name('purchase-orders.suppliers.search');

    Route::get('purchase-orders/items/search', function (Request $request) {
        if (! Schema::hasTable('inventory_items')) {
            return response()->json([]);
        }

        $term = trim((string) $request->query('q', ''));
        if ($term === '' || mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $like = '%'.$term.'%';
        $query = InventoryItem::query()
            ->where(function ($q) use ($like) {
                $q->where('item_code', 'like', $like)
                    ->orWhere('name', 'like', $like);
            });

        if (Schema::hasColumn('inventory_items', 'status')) {
            $query->where('status', 'active');
        }

        $items = $query
            ->orderBy('name')
            ->select(['id', 'name', 'item_code'])
            ->limit(20)
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
    })->name('purchase-orders.items.search');

    Volt::route('menu-items/availability', 'menu-items.availability')->name('menu-items.availability');
    Volt::route('menu-items/categorize', 'menu-items.categorize')->name('menu-items.categorize');
    Volt::route('menu-items/create', 'menu-items.create')->name('menu-items.create');
    Volt::route('menu-items/{menuItem}/edit', 'menu-items.edit')->name('menu-items.edit');

    Volt::route('purchase-orders', 'purchase-orders.index')->name('purchase-orders.index');
    Volt::route('purchase-orders/create', 'purchase-orders.create')->name('purchase-orders.create');
    Route::get('purchase-orders/{purchaseOrder}/print', function (PurchaseOrder $purchaseOrder) {
        return view('purchase-orders.print', [
            'purchaseOrder' => $purchaseOrder->load(['items.item', 'supplier', 'creator']),
        ]);
    })->name('purchase-orders.document-print');
    Volt::route('purchase-orders/{purchaseOrder}', 'purchase-orders.show')->name('purchase-orders.show');
    Volt::route('purchase-orders/{purchaseOrder}/edit', 'purchase-orders.edit')->name('purchase-orders.edit');

});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|staff|finance.access'])->group(function () {
    Volt::route('payables', 'payables.index')->name('payables.index');
    Volt::route('payables/create', 'payables.create')->name('payables.create');
    Volt::route('payables/invoices/create', 'payables.invoices.create')->name('payables.invoices.create');
    Volt::route('payables/invoices/{invoice}', 'payables.invoices.show')->name('payables.invoices.show');
    Volt::route('payables/invoices/{invoice}/edit', 'payables.invoices.edit')->name('payables.invoices.edit');
    Route::get('spend', fn () => redirect()->route('payables.index', ['tab' => 'approvals']))->name('spend.index');
    Route::get('petty-cash', fn () => redirect()->route('payables.index', ['tab' => 'expenses', 'expense_channel' => 'petty_cash']))->name('petty-cash.index');
    Route::get('expenses', fn () => redirect()->route('payables.index', ['tab' => 'expenses']))->name('expenses.index');
    Route::get('expenses/create', fn () => redirect()->route('payables.create'))->name('expenses.create');
    Route::get('expenses/categories', fn () => redirect()->route('payables.index', ['tab' => 'expenses']))->name('expenses.categories');
    Route::get('expenses/{expense}', fn ($expense) => redirect()->route('payables.invoices.show', $expense))->name('expenses.show');
    Route::get('expenses/{expense}/edit', fn ($expense) => redirect()->route('payables.invoices.edit', $expense))->name('expenses.edit');
    Volt::route('accounting', 'accounting.dashboard')->name('accounting.dashboard');
    Route::redirect('accounting/chart-of-accounts', 'settings/accounting?tab=chart')->name('accounting.chart-of-accounts');
    Volt::route('accounting/banking', 'accounting.banking')->name('accounting.banking');
    Volt::route('accounting/journals', 'accounting.journals')->name('accounting.journals');
    Volt::route('accounting/budgets', 'accounting.budgets')->name('accounting.budgets');
    Volt::route('accounting/jobs', 'accounting.jobs')->name('accounting.jobs');
    Route::get('accounting/reports', function () {
        return redirect()->route('reports.index', ['category' => 'accounting']);
    })->name('accounting.reports');
    Volt::route('accounting/period-close', 'accounting.period-close')->name('accounting.period-close');
});

Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|finance.access'])->group(function () {
    Volt::route('payables/payments/create', 'payables.payments.create')->name('payables.payments.create');
    Volt::route('payables/payments/{payment}', 'payables.payments.show')->name('payables.payments.show');
});

// POS Android app download
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('tools/pos-app', function () {
        $path = storage_path('app/apk/pos.apk');
        if (! file_exists($path)) {
            abort(404, 'POS app APK not found. Please upload the APK to storage/app/apk/pos.apk');
        }

        return response()->download($path, 'pos.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    })->name('tools.pos-app');
});

// POS (front-of-house) disabled on web
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('pos', fn () => abort(404))->name('pos.index');
    Route::get('pos/shift', fn () => abort(404))->name('pos.shift');
    Route::get('pos/branch', fn () => abort(404))->name('pos.branch-select');
});

// Sales tickets (POS receipts)
Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|cashier|orders.access'])->group(function () {
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
Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|receivables.access'])->group(function () {
    Volt::route('receivables/payments', 'receivables.payments.index')->name('receivables.payments.index');
    Volt::route('receivables/payments/create', 'receivables.payments.create')->name('receivables.payments.create');
    Volt::route('receivables/payments/{payment}', 'receivables.payments.show')->name('receivables.payments.show');
    Route::get('receivables/payments/{payment}/print', function (\App\Models\Payment $payment) {
        $payment->load(['customer', 'allocations.allocatable']);
        return view('receivables.payment-receipt', ['payment' => $payment]);
    })->name('receivables.payments.print');

    Volt::route('receivables/orders-to-invoice', 'receivables.orders-to-invoice')->name('receivables.orders-to-invoice');

    Volt::route('invoices', 'receivables.invoices.index')->name('invoices.index');
    Volt::route('invoices/create', 'receivables.invoices.create')->name('invoices.create');
    Volt::route('invoices/create/{order_id}', 'receivables.invoices.create')->name('invoices.create-from-order');
    Volt::route('invoices/{invoice}/edit', 'receivables.invoices.create')->name('invoices.edit');
    Volt::route('invoices/{invoice}', 'receivables.invoices.show')->name('invoices.show');
    Route::get('invoices/{invoice}/print', function (\App\Models\ArInvoice $invoice) {
        $invoice->load(['items', 'customer', 'paymentAllocations.payment']);
        return view('receivables.invoice-print', ['invoice' => $invoice]);
    })->name('invoices.print');
});

// Reports (manager/staff)
Route::middleware(['auth', 'active', 'role_or_permission:admin|manager|staff|reports.access', 'reports.default-dates'])->prefix('reports')->name('reports.')->group(function () {
    Volt::route('/', 'reports.index')->name('index');
    Volt::route('orders', 'reports.orders')->name('orders');
    Volt::route('purchase-orders', 'reports.purchase-orders')->name('purchase-orders');
    Volt::route('purchase-order-detail', 'reports.purchase-order-detail')->name('purchase-order-detail');
    Volt::route('expenses', 'reports.expenses')->name('expenses');
    Volt::route('costing', 'reports.costing')->name('costing');
    Volt::route('sales', 'reports.sales')->name('sales');
    Volt::route('inventory', 'reports.inventory')->name('inventory');
    Volt::route('payables', 'reports.payables')->name('payables');
    Volt::route('receivables', 'reports.receivables')->name('receivables');
    Volt::route('customer-advances', 'reports.customer-advances')->name('customer-advances');
    Volt::route('sales-all', 'reports.sales-all')->name('sales-all');
    Volt::route('session-branch-sales', 'reports.session-branch-sales')->name('session-branch-sales');
    Volt::route('daily-sales', 'reports.daily-sales')->name('daily-sales');
    Volt::route('sales-entry-daily', 'reports.sales-entry-daily')->name('sales-entry-daily');
    Volt::route('sales-entry-monthly', 'reports.sales-entry-monthly')->name('sales-entry-monthly');
    Volt::route('category-sales-summary', 'reports.category-sales-summary')->name('category-sales-summary');
    Volt::route('receivables-summary', 'reports.receivables-summary')->name('receivables-summary');
    Volt::route('customer-statement', 'reports.customer-statement')->name('customer-statement');
    Volt::route('customers-statement', 'reports.customers-statement')->name('customers-statement');
    Volt::route('statement-of-accounts', 'reports.statement-of-accounts')->name('statement-of-accounts');
    Volt::route('supplier-statement', 'reports.supplier-statement')->name('supplier-statement');
    Volt::route('suppliers-statement', 'reports.suppliers-statement')->name('suppliers-statement');
    Volt::route('payables-summary', 'reports.payables-summary')->name('payables-summary');
    Volt::route('monthly-branch-sales', 'reports.monthly-branch-sales')->name('monthly-branch-sales');
    Volt::route('customer-aging-summary', 'reports.customer-aging-summary')->name('customer-aging-summary');
    Volt::route('supplier-aging-summary', 'reports.supplier-aging-summary')->name('supplier-aging-summary');
    Volt::route('subscription-details', 'reports.subscription-details')->name('subscription-details');
    Volt::route('accounting/trial-balance', 'reports.accounting-report')->name('accounting-trial-balance');
    Volt::route('accounting/profit-loss', 'reports.accounting-report')->name('accounting-profit-loss');
    Volt::route('accounting/balance-sheet', 'reports.accounting-report')->name('accounting-balance-sheet');
    Volt::route('accounting/cash-flow', 'reports.accounting-report')->name('accounting-cash-flow');
    Volt::route('accounting/bank-reconciliation', 'reports.accounting-report')->name('accounting-bank-reconciliation');
    Volt::route('accounting/budget-variance', 'reports.accounting-report')->name('accounting-budget-variance');
    Volt::route('accounting/job-profitability', 'reports.accounting-report')->name('accounting-job-profitability');
    Volt::route('accounting/ap-aging', 'reports.accounting-report')->name('accounting-ap-aging');
    Volt::route('accounting/vendor-ledger', 'reports.accounting-report')->name('accounting-vendor-ledger');
    Volt::route('accounting/expense-analysis', 'reports.accounting-report')->name('accounting-expense-analysis');
    Volt::route('accounting/inventory-valuation', 'reports.accounting-report')->name('accounting-inventory-valuation');
    Volt::route('accounting/purchase-accruals', 'reports.accounting-report')->name('accounting-purchase-accruals');
    Volt::route('accounting/multi-company-summary', 'reports.accounting-report')->name('accounting-multi-company-summary');

    Route::get('orders/print', [\App\Http\Controllers\Reports\OrdersReportController::class, 'print'])->name('orders.print');
    Route::get('orders/csv', [\App\Http\Controllers\Reports\OrdersReportController::class, 'csv'])->name('orders.csv');
    Route::get('orders/pdf', [\App\Http\Controllers\Reports\OrdersReportController::class, 'pdf'])->name('orders.pdf');
    Route::get('purchase-orders/print', [\App\Http\Controllers\Reports\PurchaseOrdersReportController::class, 'print'])->name('purchase-orders.print');
    Route::get('purchase-orders/csv', [\App\Http\Controllers\Reports\PurchaseOrdersReportController::class, 'csv'])->name('purchase-orders.csv');
    Route::get('purchase-orders/pdf', [\App\Http\Controllers\Reports\PurchaseOrdersReportController::class, 'pdf'])->name('purchase-orders.pdf');
    Route::get('purchase-order-detail/print', [\App\Http\Controllers\Reports\PurchaseOrderDetailReportController::class, 'print'])->name('purchase-order-detail.print');
    Route::get('purchase-order-detail/csv', [\App\Http\Controllers\Reports\PurchaseOrderDetailReportController::class, 'csv'])->name('purchase-order-detail.csv');
    Route::get('purchase-order-detail/pdf', [\App\Http\Controllers\Reports\PurchaseOrderDetailReportController::class, 'pdf'])->name('purchase-order-detail.pdf');
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
    Route::get('customer-advances/print', [\App\Http\Controllers\Reports\CustomerAdvancesReportController::class, 'print'])->name('customer-advances.print');
    Route::get('customer-advances/csv', [\App\Http\Controllers\Reports\CustomerAdvancesReportController::class, 'csv'])->name('customer-advances.csv');
    Route::get('customer-advances/pdf', [\App\Http\Controllers\Reports\CustomerAdvancesReportController::class, 'pdf'])->name('customer-advances.pdf');
    Route::get('sales-all/print', [\App\Http\Controllers\Reports\SalesAllReportController::class, 'print'])->name('sales-all.print');
    Route::get('sales-all/csv', [\App\Http\Controllers\Reports\SalesAllReportController::class, 'csv'])->name('sales-all.csv');
    Route::get('sales-all/pdf', [\App\Http\Controllers\Reports\SalesAllReportController::class, 'pdf'])->name('sales-all.pdf');
    Route::get('session-branch-sales/print', [\App\Http\Controllers\Reports\SessionBranchSalesReportController::class, 'print'])->name('session-branch-sales.print');
    Route::get('session-branch-sales/csv', [\App\Http\Controllers\Reports\SessionBranchSalesReportController::class, 'csv'])->name('session-branch-sales.csv');
    Route::get('session-branch-sales/pdf', [\App\Http\Controllers\Reports\SessionBranchSalesReportController::class, 'pdf'])->name('session-branch-sales.pdf');
    Route::get('daily-sales/print', [\App\Http\Controllers\Reports\DailySalesReportController::class, 'print'])->name('daily-sales.print');
    Route::get('daily-sales/csv', [\App\Http\Controllers\Reports\DailySalesReportController::class, 'csv'])->name('daily-sales.csv');
    Route::get('daily-sales/pdf', [\App\Http\Controllers\Reports\DailySalesReportController::class, 'pdf'])->name('daily-sales.pdf');
    Route::get('sales-entry-daily/print', [\App\Http\Controllers\Reports\SalesEntryDailyReportController::class, 'print'])->name('sales-entry-daily.print');
    Route::get('sales-entry-daily/csv', [\App\Http\Controllers\Reports\SalesEntryDailyReportController::class, 'csv'])->name('sales-entry-daily.csv');
    Route::get('sales-entry-daily/pdf', [\App\Http\Controllers\Reports\SalesEntryDailyReportController::class, 'pdf'])->name('sales-entry-daily.pdf');
    Route::get('sales-entry-monthly/print', [\App\Http\Controllers\Reports\SalesEntryMonthlyReportController::class, 'print'])->name('sales-entry-monthly.print');
    Route::get('sales-entry-monthly/csv', [\App\Http\Controllers\Reports\SalesEntryMonthlyReportController::class, 'csv'])->name('sales-entry-monthly.csv');
    Route::get('sales-entry-monthly/pdf', [\App\Http\Controllers\Reports\SalesEntryMonthlyReportController::class, 'pdf'])->name('sales-entry-monthly.pdf');
    Route::get('category-sales-summary/print', [\App\Http\Controllers\Reports\CategorySalesSummaryReportController::class, 'print'])->name('category-sales-summary.print');
    Route::get('category-sales-summary/csv', [\App\Http\Controllers\Reports\CategorySalesSummaryReportController::class, 'csv'])->name('category-sales-summary.csv');
    Route::get('category-sales-summary/pdf', [\App\Http\Controllers\Reports\CategorySalesSummaryReportController::class, 'pdf'])->name('category-sales-summary.pdf');
    Route::get('receivables-summary/print', [\App\Http\Controllers\Reports\ReceivablesSummaryReportController::class, 'print'])->name('receivables-summary.print');
    Route::get('receivables-summary/csv', [\App\Http\Controllers\Reports\ReceivablesSummaryReportController::class, 'csv'])->name('receivables-summary.csv');
    Route::get('receivables-summary/pdf', [\App\Http\Controllers\Reports\ReceivablesSummaryReportController::class, 'pdf'])->name('receivables-summary.pdf');
    Route::get('customer-statement/print', [\App\Http\Controllers\Reports\CustomerStatementReportController::class, 'print'])->name('customer-statement.print');
    Route::get('customer-statement/csv', [\App\Http\Controllers\Reports\CustomerStatementReportController::class, 'csv'])->name('customer-statement.csv');
    Route::get('customer-statement/pdf', [\App\Http\Controllers\Reports\CustomerStatementReportController::class, 'pdf'])->name('customer-statement.pdf');
    Route::get('customers-statement/print', [\App\Http\Controllers\Reports\CustomersStatementReportController::class, 'print'])->name('customers-statement.print');
    Route::get('customers-statement/csv', [\App\Http\Controllers\Reports\CustomersStatementReportController::class, 'csv'])->name('customers-statement.csv');
    Route::get('customers-statement/pdf', [\App\Http\Controllers\Reports\CustomersStatementReportController::class, 'pdf'])->name('customers-statement.pdf');
    Route::get('statement-of-accounts/print', [\App\Http\Controllers\Reports\StatementOfAccountsReportController::class, 'print'])->name('statement-of-accounts.print');
    Route::get('statement-of-accounts/csv', [\App\Http\Controllers\Reports\StatementOfAccountsReportController::class, 'csv'])->name('statement-of-accounts.csv');
    Route::get('statement-of-accounts/pdf', [\App\Http\Controllers\Reports\StatementOfAccountsReportController::class, 'pdf'])->name('statement-of-accounts.pdf');
    Route::get('supplier-statement/print', [\App\Http\Controllers\Reports\SupplierStatementReportController::class, 'print'])->name('supplier-statement.print');
    Route::get('supplier-statement/csv', [\App\Http\Controllers\Reports\SupplierStatementReportController::class, 'csv'])->name('supplier-statement.csv');
    Route::get('supplier-statement/pdf', [\App\Http\Controllers\Reports\SupplierStatementReportController::class, 'pdf'])->name('supplier-statement.pdf');
    Route::get('suppliers-statement/print', [\App\Http\Controllers\Reports\SuppliersStatementReportController::class, 'print'])->name('suppliers-statement.print');
    Route::get('suppliers-statement/csv', [\App\Http\Controllers\Reports\SuppliersStatementReportController::class, 'csv'])->name('suppliers-statement.csv');
    Route::get('suppliers-statement/pdf', [\App\Http\Controllers\Reports\SuppliersStatementReportController::class, 'pdf'])->name('suppliers-statement.pdf');
    Route::get('payables-summary/print', [\App\Http\Controllers\Reports\PayablesSummaryReportController::class, 'print'])->name('payables-summary.print');
    Route::get('payables-summary/csv', [\App\Http\Controllers\Reports\PayablesSummaryReportController::class, 'csv'])->name('payables-summary.csv');
    Route::get('payables-summary/pdf', [\App\Http\Controllers\Reports\PayablesSummaryReportController::class, 'pdf'])->name('payables-summary.pdf');
    Route::get('monthly-branch-sales/print', [\App\Http\Controllers\Reports\MonthlyBranchSalesReportController::class, 'print'])->name('monthly-branch-sales.print');
    Route::get('monthly-branch-sales/csv', [\App\Http\Controllers\Reports\MonthlyBranchSalesReportController::class, 'csv'])->name('monthly-branch-sales.csv');
    Route::get('monthly-branch-sales/pdf', [\App\Http\Controllers\Reports\MonthlyBranchSalesReportController::class, 'pdf'])->name('monthly-branch-sales.pdf');
    Route::get('customer-aging-summary/print', [\App\Http\Controllers\Reports\CustomerAgingSummaryReportController::class, 'print'])->name('customer-aging-summary.print');
    Route::get('customer-aging-summary/csv', [\App\Http\Controllers\Reports\CustomerAgingSummaryReportController::class, 'csv'])->name('customer-aging-summary.csv');
    Route::get('customer-aging-summary/pdf', [\App\Http\Controllers\Reports\CustomerAgingSummaryReportController::class, 'pdf'])->name('customer-aging-summary.pdf');
    Route::get('supplier-aging-summary/print', [\App\Http\Controllers\Reports\SupplierAgingSummaryReportController::class, 'print'])->name('supplier-aging-summary.print');
    Route::get('supplier-aging-summary/csv', [\App\Http\Controllers\Reports\SupplierAgingSummaryReportController::class, 'csv'])->name('supplier-aging-summary.csv');
    Route::get('supplier-aging-summary/pdf', [\App\Http\Controllers\Reports\SupplierAgingSummaryReportController::class, 'pdf'])->name('supplier-aging-summary.pdf');
    Route::get('accounting/export/{report}.csv', [\App\Http\Controllers\Reports\AccountingReportExportController::class, 'csv'])->name('accounting.export.csv');
    Route::get('subscription-details/print', [\App\Http\Controllers\Reports\SubscriptionDetailsReportController::class, 'print'])->name('subscription-details.print');
    Route::get('subscription-details/csv', [\App\Http\Controllers\Reports\SubscriptionDetailsReportController::class, 'csv'])->name('subscription-details.csv');
});

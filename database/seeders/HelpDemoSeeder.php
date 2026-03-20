<?php

namespace Database\Seeders;

use App\Models\ApInvoice;
use App\Models\ArInvoice;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CompanyFoodEmployee;
use App\Models\CompanyFoodEmployeeList;
use App\Models\CompanyFoodListCategory;
use App\Models\CompanyFoodOption;
use App\Models\CompanyFoodOrder;
use App\Models\CompanyFoodProject;
use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\InventoryItem;
use App\Models\MealPlanRequest;
use App\Models\MealSubscription;
use App\Models\MealSubscriptionDay;
use App\Models\MealSubscriptionOrder;
use App\Models\MealSubscriptionPause;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeProduction;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

class HelpDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedUsers();
        $this->seedBranches();
        $this->seedCoreReferenceData();
        $this->seedOperationalData();
        $this->seedRecipeData();
        $this->seedSubscriptionData();
        $this->seedCompanyFoodData();
    }

    private function seedUsers(): void
    {
        $definitions = [
            ['username' => 'help.admin', 'email' => 'help.admin@example.com', 'role' => 'admin', 'pos_enabled' => true],
            ['username' => 'help.manager', 'email' => 'help.manager@example.com', 'role' => 'manager', 'pos_enabled' => true],
            ['username' => 'help.cashier', 'email' => 'help.cashier@example.com', 'role' => 'cashier', 'pos_enabled' => true],
            ['username' => 'help.staff', 'email' => 'help.staff@example.com', 'role' => 'staff', 'pos_enabled' => false],
        ];

        foreach ($definitions as $definition) {
            $user = User::query()->updateOrCreate(
                ['email' => $definition['email']],
                [
                    'name' => ucfirst(explode('.', $definition['username'])[1]).' Demo',
                    'username' => $definition['username'],
                    'status' => 'active',
                    'password' => Hash::make('password'),
                    'pos_enabled' => $definition['pos_enabled'],
                    'email_verified_at' => now(),
                ],
            );

            $role = Role::findOrCreate($definition['role'], 'web');
            $user->syncRoles([$role->name]);
        }
    }

    private function seedBranches(): void
    {
        if (! Schema::hasTable('branches')) {
            return;
        }

        $branches = [
            ['code' => 'MAIN', 'name' => 'Main Branch', 'is_active' => true],
            ['code' => 'CATR', 'name' => 'Catering Branch', 'is_active' => true],
        ];

        foreach ($branches as $branch) {
            Branch::query()->updateOrCreate(['code' => $branch['code']], $branch);
        }

        if (! Schema::hasTable('user_branch_access')) {
            return;
        }

        $branchIds = Branch::query()->pluck('id')->all();
        $users = User::query()->whereIn('username', ['help.manager', 'help.cashier', 'help.staff'])->get();

        foreach ($users as $user) {
            foreach ($branchIds as $branchId) {
                DB::table('user_branch_access')->updateOrInsert(
                    ['user_id' => $user->id, 'branch_id' => $branchId],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    private function seedCoreReferenceData(): void
    {
        $category = Category::query()->firstOrCreate(
            ['name' => 'Demo Meals'],
            ['description' => 'Seed category for Help Center captures', 'parent_id' => null],
        );

        $supplier = Supplier::query()->firstOrCreate(
            ['email' => 'supplier.help@example.com'],
            [
                'name' => 'Help Supplier',
                'contact_person' => 'Help Buyer',
                'phone' => '+97455501001',
                'address' => 'Demo Logistics Street',
                'qid_cr' => 'HELP-1001',
                'status' => 'active',
            ],
        );

        Customer::query()->firstOrCreate(
            ['email' => 'customer.help@example.com'],
            [
                'customer_code' => 'HELP-CUST-001',
                'name' => 'Help Customer',
                'customer_type' => 'corporate',
                'contact_name' => 'Operations Desk',
                'phone' => '+97455502002',
                'billing_address' => 'Help Customer Billing Address',
                'delivery_address' => 'Help Customer Delivery Address',
                'country' => 'Qatar',
                'credit_limit' => 5000,
                'credit_terms_days' => 30,
                'is_active' => true,
            ],
        );

        if (Schema::hasTable('inventory_items')) {
            $inventoryItem = InventoryItem::query()->firstOrCreate(
                ['item_code' => 'HELP-ITEM-001'],
                [
                    'name' => 'Help Chicken Breast',
                    'description' => 'Seed inventory item for transfers and POs',
                    'category_id' => $category->id,
                    'supplier_id' => $supplier->id,
                    'units_per_package' => 1,
                    'package_label' => 'pack',
                    'unit_of_measure' => 'kg',
                    'minimum_stock' => 5,
                    'cost_per_unit' => 12.5000,
                    'last_cost_update' => now(),
                    'location' => 'A-01',
                    'status' => 'active',
                ],
            );

            if (Schema::hasTable('inventory_stocks')) {
                foreach (Branch::query()->pluck('id') as $branchId) {
                    DB::table('inventory_stocks')->updateOrInsert(
                        ['inventory_item_id' => $inventoryItem->id, 'branch_id' => $branchId],
                        ['current_stock' => 25, 'updated_at' => now(), 'created_at' => now()],
                    );
                }
            }
        }

        if (Schema::hasTable('menu_items')) {
            $menuItems = [
                ['code' => 'HELP-MENU-001', 'name' => 'Help Grilled Chicken Bowl'],
                ['code' => 'HELP-MENU-002', 'name' => 'Help Beef Rice Box'],
                ['code' => 'HELP-MENU-003', 'name' => 'Help Salad Box'],
            ];

            foreach ($menuItems as $menuItem) {
                $item = MenuItem::query()->firstOrCreate(
                    ['code' => $menuItem['code']],
                    [
                        'name' => $menuItem['name'],
                        'arabic_name' => $menuItem['name'],
                        'category_id' => $category->id,
                        'selling_price_per_unit' => 18.000,
                        'unit' => 'each',
                        'tax_rate' => 0,
                        'is_active' => true,
                        'display_order' => 1,
                    ],
                );

                if (Schema::hasTable('menu_item_branches')) {
                    foreach (Branch::query()->pluck('id') as $branchId) {
                        DB::table('menu_item_branches')->updateOrInsert(
                            ['menu_item_id' => $item->id, 'branch_id' => $branchId],
                            ['created_at' => now(), 'updated_at' => now()],
                        );
                    }
                }
            }
        }
    }

    private function seedOperationalData(): void
    {
        $manager = User::query()->where('username', 'help.manager')->first();
        $customer = Customer::query()->where('email', 'customer.help@example.com')->first();
        $supplier = Supplier::query()->where('email', 'supplier.help@example.com')->first();
        $branchId = (int) (Branch::query()->where('code', 'MAIN')->value('id') ?: 1);

        if ($customer && Schema::hasTable('orders')) {
            Order::query()->firstOrCreate(
                ['order_number' => 'HELP-ORDER-001'],
                [
                    'branch_id' => $branchId,
                    'source' => 'Backoffice',
                    'is_daily_dish' => false,
                    'type' => 'Delivery',
                    'status' => 'Confirmed',
                    'customer_id' => $customer->id,
                    'customer_name_snapshot' => $customer->name,
                    'customer_phone_snapshot' => $customer->phone,
                    'delivery_address_snapshot' => $customer->delivery_address,
                    'scheduled_date' => now()->toDateString(),
                    'scheduled_time' => now()->format('H:i:s'),
                    'notes' => 'Demo order for Help Center',
                    'total_before_tax' => 1800,
                    'tax_amount' => 0,
                    'total_amount' => 1800,
                    'created_by' => $manager?->id,
                ],
            );
        }

        if ($supplier && Schema::hasTable('purchase_orders')) {
            PurchaseOrder::query()->firstOrCreate(
                ['po_number' => 'HELP-PO-001'],
                [
                    'supplier_id' => $supplier->id,
                    'order_date' => now()->toDateString(),
                    'expected_delivery_date' => now()->addDays(2)->toDateString(),
                    'status' => 'draft',
                    'total_amount' => 250,
                    'notes' => 'Demo purchase order',
                    'created_by' => $manager?->id,
                ],
            );
        }

        if ($customer && Schema::hasTable('ar_invoices')) {
            ArInvoice::query()->firstOrCreate(
                ['invoice_number' => 'HELP-AR-001'],
                [
                    'branch_id' => $branchId,
                    'customer_id' => $customer->id,
                    'type' => 'invoice',
                    'status' => 'issued',
                    'payment_type' => 'credit',
                    'payment_term_days' => 30,
                    'issue_date' => now()->toDateString(),
                    'due_date' => now()->addDays(30)->toDateString(),
                    'currency' => (string) config('pos.currency'),
                    'subtotal_cents' => 180000,
                    'discount_total_cents' => 0,
                    'invoice_discount_type' => 'fixed',
                    'invoice_discount_value' => 0,
                    'invoice_discount_cents' => 0,
                    'tax_total_cents' => 0,
                    'total_cents' => 180000,
                    'paid_total_cents' => 0,
                    'balance_cents' => 180000,
                    'created_by' => $manager?->id,
                    'updated_by' => $manager?->id,
                ],
            );
        }

        if ($customer && Schema::hasTable('payments')) {
            Payment::query()->firstOrCreate(
                ['reference' => 'HELP-PAY-001'],
                [
                    'branch_id' => $branchId,
                    'customer_id' => $customer->id,
                    'source' => 'backoffice',
                    'method' => 'cash',
                    'amount_cents' => 50000,
                    'currency' => (string) config('pos.currency'),
                    'received_at' => now(),
                    'notes' => 'Demo payment for Help Center',
                    'created_by' => $manager?->id,
                ],
            );
        }

        if ($supplier && Schema::hasTable('ap_invoices')) {
            ApInvoice::query()->firstOrCreate(
                ['invoice_number' => 'HELP-AP-001'],
                [
                    'supplier_id' => $supplier->id,
                    'is_expense' => true,
                    'invoice_date' => now()->toDateString(),
                    'due_date' => now()->addDays(7)->toDateString(),
                    'subtotal' => 120,
                    'tax_amount' => 0,
                    'total_amount' => 120,
                    'status' => 'draft',
                    'notes' => 'Demo spend invoice',
                    'created_by' => $manager?->id,
                ],
            );
        }

        if (Schema::hasTable('daily_dish_menus') && Schema::hasTable('menu_items')) {
            $menu = DailyDishMenu::query()->firstOrCreate(
                ['branch_id' => $branchId, 'service_date' => now()->toDateString()],
                [
                    'status' => 'draft',
                    'notes' => 'Demo Daily Dish menu',
                    'created_by' => $manager?->id,
                ],
            );

            $menuItemId = MenuItem::query()->value('id');
            if ($menuItemId && Schema::hasTable('daily_dish_menu_items')) {
                $payload = [
                    'daily_dish_menu_id' => $menu->id,
                    'menu_item_id' => $menuItemId,
                ];

                if (Schema::hasColumn('daily_dish_menu_items', 'created_at')) {
                    $payload['created_at'] = now();
                }
                if (Schema::hasColumn('daily_dish_menu_items', 'updated_at')) {
                    $payload['updated_at'] = now();
                }

                if (Schema::hasColumn('daily_dish_menu_items', 'role')) {
                    $payload['role'] = 'main';
                }
                if (Schema::hasColumn('daily_dish_menu_items', 'sort_order')) {
                    $payload['sort_order'] = 1;
                }
                if (Schema::hasColumn('daily_dish_menu_items', 'is_required')) {
                    $payload['is_required'] = 1;
                }
                if (Schema::hasColumn('daily_dish_menu_items', 'price')) {
                    $payload['price'] = 18;
                }
                if (Schema::hasColumn('daily_dish_menu_items', 'unit')) {
                    $payload['unit'] = 'each';
                }

                DB::table('daily_dish_menu_items')->updateOrInsert(
                    ['daily_dish_menu_id' => $menu->id, 'menu_item_id' => $menuItemId],
                    $payload,
                );
            }
        }
    }

    private function seedRecipeData(): void
    {
        if (! Schema::hasTable('recipes') || ! Schema::hasTable('recipe_items')) {
            return;
        }

        $manager = User::query()->where('username', 'help.manager')->first();
        $categoryId = Category::query()->where('name', 'Demo Meals')->value('id');
        $inventoryItem = InventoryItem::query()->where('item_code', 'HELP-ITEM-001')->first();
        $menuItem = MenuItem::query()->where('code', 'HELP-MENU-001')->first();

        if (! $categoryId || ! $inventoryItem) {
            return;
        }

        $subRecipe = Recipe::query()->updateOrCreate(
            ['name' => 'Help Lemon Herb Sauce'],
            [
                'description' => 'Demo sub-recipe for Help Center screenshots.',
                'category_id' => $categoryId,
                'yield_quantity' => 2.000,
                'yield_unit' => 'liter',
                'overhead_pct' => 5.0000,
                'selling_price_per_unit' => 6.5000,
                'status' => 'published',
            ],
        );

        $mainRecipe = Recipe::query()->updateOrCreate(
            ['name' => 'Help Citrus Chicken Bowl'],
            [
                'description' => 'Demo recipe with inventory and sub-recipe ingredients.',
                'category_id' => $categoryId,
                'yield_quantity' => 6.000,
                'yield_unit' => 'portion',
                'overhead_pct' => 8.0000,
                'selling_price_per_unit' => 21.0000,
                'status' => 'published',
            ],
        );

        RecipeItem::query()->whereIn('recipe_id', [$subRecipe->id, $mainRecipe->id])->delete();

        RecipeItem::query()->create([
            'recipe_id' => $subRecipe->id,
            'inventory_item_id' => $inventoryItem->id,
            'quantity' => 0.750,
            'unit' => 'kg',
            'quantity_type' => 'unit',
            'cost_type' => 'ingredient',
        ]);

        RecipeItem::query()->create([
            'recipe_id' => $mainRecipe->id,
            'inventory_item_id' => $inventoryItem->id,
            'quantity' => 1.500,
            'unit' => 'kg',
            'quantity_type' => 'unit',
            'cost_type' => 'ingredient',
        ]);

        $subRecipePayload = [
            'recipe_id' => $mainRecipe->id,
            'quantity' => 0.500,
            'unit' => 'liter',
            'quantity_type' => 'unit',
            'cost_type' => 'ingredient',
        ];

        if (Schema::hasColumn('recipe_items', 'sub_recipe_id')) {
            $subRecipePayload['sub_recipe_id'] = $subRecipe->id;
        } else {
            $subRecipePayload['inventory_item_id'] = $inventoryItem->id;
        }

        RecipeItem::query()->create($subRecipePayload);

        if ($menuItem && Schema::hasColumn('menu_items', 'recipe_id')) {
            $menuItem->forceFill(['recipe_id' => $mainRecipe->id])->save();
        }

        if (Schema::hasTable('recipe_productions')) {
            RecipeProduction::query()->updateOrCreate(
                ['reference' => 'HELP-RECIPE-PROD-001'],
                [
                    'recipe_id' => $mainRecipe->id,
                    'produced_quantity' => 12.000,
                    'production_date' => now()->subDay(),
                    'notes' => 'Demo production batch for Help Center',
                    'created_by' => $manager?->id,
                ],
            );
        }
    }

    private function seedSubscriptionData(): void
    {
        $manager = User::query()->where('username', 'help.manager')->first();
        $customer = Customer::query()->where('email', 'customer.help@example.com')->first();
        $branchId = (int) (Branch::query()->where('code', 'MAIN')->value('id') ?: 1);

        if (Schema::hasTable('orders') && $customer) {
            Order::query()->updateOrCreate(
                ['order_number' => 'HELP-MPR-PLAN-001'],
                [
                    'branch_id' => $branchId,
                    'source' => 'Website',
                    'is_daily_dish' => true,
                    'type' => 'Delivery',
                    'status' => 'Draft',
                    'customer_id' => $customer->id,
                    'customer_name_snapshot' => 'Help Meal Plan Prospect',
                    'customer_phone_snapshot' => $customer->phone,
                    'delivery_address_snapshot' => $customer->delivery_address,
                    'scheduled_date' => now()->addDay()->toDateString(),
                    'scheduled_time' => '12:30:00',
                    'notes' => 'Demo meal plan request order',
                    'total_before_tax' => 320,
                    'tax_amount' => 0,
                    'total_amount' => 320,
                    'created_by' => $manager?->id,
                ],
            );

            Order::query()->updateOrCreate(
                ['order_number' => 'HELP-MPR-NOPLAN-001'],
                [
                    'branch_id' => $branchId,
                    'source' => 'Website',
                    'is_daily_dish' => true,
                    'type' => 'Delivery',
                    'status' => 'Draft',
                    'customer_id' => $customer->id,
                    'customer_name_snapshot' => 'Help Meal Plan Walk-In',
                    'customer_phone_snapshot' => '+97455503002',
                    'delivery_address_snapshot' => 'Help Walk-In Delivery Address',
                    'scheduled_date' => now()->addDays(2)->toDateString(),
                    'scheduled_time' => '13:00:00',
                    'notes' => 'Demo no-plan request order',
                    'total_before_tax' => 150,
                    'tax_amount' => 0,
                    'total_amount' => 150,
                    'created_by' => $manager?->id,
                ],
            );

            Order::query()->updateOrCreate(
                ['order_number' => 'HELP-SUB-ORDER-001'],
                [
                    'branch_id' => $branchId,
                    'source' => 'Subscription',
                    'is_daily_dish' => true,
                    'type' => 'Delivery',
                    'status' => 'Confirmed',
                    'customer_id' => $customer->id,
                    'customer_name_snapshot' => $customer->name,
                    'customer_phone_snapshot' => $customer->phone,
                    'delivery_address_snapshot' => $customer->delivery_address,
                    'scheduled_date' => now()->toDateString(),
                    'scheduled_time' => '12:00:00',
                    'notes' => 'Demo generated subscription order',
                    'total_before_tax' => 200,
                    'tax_amount' => 0,
                    'total_amount' => 200,
                    'created_by' => $manager?->id,
                ],
            );
        }

        if (Schema::hasTable('meal_plan_requests')) {
            $plannedRequest = MealPlanRequest::query()->updateOrCreate(
                ['customer_name' => 'Help Meal Plan Prospect'],
                [
                    'customer_phone' => $customer?->phone ?: '+97455502002',
                    'customer_email' => $customer?->email ?: 'customer.help@example.com',
                    'delivery_address' => $customer?->delivery_address ?: 'Help Customer Delivery Address',
                    'notes' => 'Demo meal plan request ready for conversion.',
                    'plan_meals' => 20,
                    'status' => 'new',
                ],
            );

            $walkInRequest = MealPlanRequest::query()->updateOrCreate(
                ['customer_name' => 'Help Meal Plan Walk-In'],
                [
                    'customer_phone' => '+97455503002',
                    'customer_email' => 'walkin.help@example.com',
                    'delivery_address' => 'Help Walk-In Delivery Address',
                    'notes' => 'Demo no-plan request for accept flow.',
                    'plan_meals' => 0,
                    'status' => 'new',
                ],
            );

            if (Schema::hasTable('meal_plan_request_orders')) {
                $plannedOrderId = Order::query()->where('order_number', 'HELP-MPR-PLAN-001')->value('id');
                $walkInOrderId = Order::query()->where('order_number', 'HELP-MPR-NOPLAN-001')->value('id');

                if ($plannedOrderId) {
                    DB::table('meal_plan_request_orders')->updateOrInsert(
                        ['meal_plan_request_id' => $plannedRequest->id, 'order_id' => $plannedOrderId],
                        ['created_at' => now(), 'updated_at' => now()],
                    );
                }

                if ($walkInOrderId) {
                    DB::table('meal_plan_request_orders')->updateOrInsert(
                        ['meal_plan_request_id' => $walkInRequest->id, 'order_id' => $walkInOrderId],
                        ['created_at' => now(), 'updated_at' => now()],
                    );
                }
            }
        }

        if (! Schema::hasTable('meal_subscriptions') || ! $customer) {
            return;
        }

        $active = MealSubscription::query()->updateOrCreate(
            ['subscription_code' => 'HELP-SUB-001'],
            [
                'customer_id' => $customer->id,
                'branch_id' => $branchId,
                'status' => 'active',
                'start_date' => now()->subDays(10)->toDateString(),
                'end_date' => null,
                'plan_meals_total' => 20,
                'meals_used' => 3,
                'meal_plan_request_id' => null,
                'default_order_type' => 'Delivery',
                'delivery_time' => '12:30',
                'address_snapshot' => $customer->delivery_address,
                'phone_snapshot' => $customer->phone,
                'preferred_role' => 'main',
                'include_salad' => true,
                'include_dessert' => true,
                'notes' => 'Demo active subscription for Help Center.',
                'created_by' => $manager?->id,
            ],
        );

        $paused = MealSubscription::query()->updateOrCreate(
            ['subscription_code' => 'HELP-SUB-002'],
            [
                'customer_id' => $customer->id,
                'branch_id' => $branchId,
                'status' => 'paused',
                'start_date' => now()->subDays(20)->toDateString(),
                'end_date' => null,
                'plan_meals_total' => 26,
                'meals_used' => 6,
                'meal_plan_request_id' => null,
                'default_order_type' => 'Delivery',
                'delivery_time' => '13:00',
                'address_snapshot' => $customer->delivery_address,
                'phone_snapshot' => $customer->phone,
                'preferred_role' => 'diet',
                'include_salad' => true,
                'include_dessert' => false,
                'notes' => 'Demo paused subscription for Help Center.',
                'created_by' => $manager?->id,
            ],
        );

        if (Schema::hasTable('meal_subscription_days')) {
            MealSubscriptionDay::query()->whereIn('subscription_id', [$active->id, $paused->id])->delete();

            foreach ([1, 2, 3, 4, 7] as $weekday) {
                MealSubscriptionDay::query()->create([
                    'subscription_id' => $active->id,
                    'weekday' => $weekday,
                ]);
            }

            foreach ([1, 2, 3, 4, 5] as $weekday) {
                MealSubscriptionDay::query()->create([
                    'subscription_id' => $paused->id,
                    'weekday' => $weekday,
                ]);
            }
        }

        if (Schema::hasTable('meal_subscription_pauses')) {
            MealSubscriptionPause::query()->updateOrCreate(
                [
                    'subscription_id' => $paused->id,
                    'pause_start' => now()->toDateString(),
                    'pause_end' => now()->addDays(3)->toDateString(),
                ],
                [
                    'reason' => 'Demo pause for Help Center',
                    'created_by' => $manager?->id,
                ],
            );
        }

        if (Schema::hasTable('meal_subscription_orders')) {
            $subscriptionOrderId = Order::query()->where('order_number', 'HELP-SUB-ORDER-001')->value('id');

            if ($subscriptionOrderId) {
                MealSubscriptionOrder::query()->updateOrCreate(
                    [
                        'subscription_id' => $active->id,
                        'order_id' => $subscriptionOrderId,
                    ],
                    [
                        'service_date' => now()->toDateString(),
                        'branch_id' => $branchId,
                    ],
                );
            }
        }
    }

    private function seedCompanyFoodData(): void
    {
        if (! Schema::hasTable('company_food_projects')) {
            return;
        }

        $project = CompanyFoodProject::query()->updateOrCreate(
            ['slug' => 'help-company-food'],
            [
                'name' => 'Help Company Food April',
                'company_name' => 'Help Industries',
                'start_date' => now()->startOfWeek()->toDateString(),
                'end_date' => now()->addDays(4)->toDateString(),
                'is_active' => true,
            ],
        );

        $primaryList = $project->employeeLists()->orderBy('sort_order')->first();
        if (! $primaryList) {
            $primaryList = CompanyFoodEmployeeList::query()->create([
                'project_id' => $project->id,
                'name' => 'Head Office',
                'sort_order' => 0,
            ]);
        } else {
            $primaryList->forceFill([
                'name' => 'Head Office',
                'sort_order' => 0,
            ])->save();
        }

        $secondaryList = CompanyFoodEmployeeList::query()->updateOrCreate(
            ['project_id' => $project->id, 'name' => 'Factory Team'],
            ['sort_order' => 1],
        );

        $this->syncCompanyFoodCategories($primaryList);
        $this->syncCompanyFoodCategories($secondaryList);

        CompanyFoodEmployee::query()->updateOrCreate(
            ['project_id' => $project->id, 'employee_list_id' => $primaryList->id, 'employee_name' => 'Help Employee One'],
            ['sort_order' => 0],
        );
        CompanyFoodEmployee::query()->updateOrCreate(
            ['project_id' => $project->id, 'employee_list_id' => $primaryList->id, 'employee_name' => 'Help Employee Two'],
            ['sort_order' => 1],
        );
        CompanyFoodEmployee::query()->updateOrCreate(
            ['project_id' => $project->id, 'employee_list_id' => $secondaryList->id, 'employee_name' => 'Help Factory Employee'],
            ['sort_order' => 0],
        );

        $dates = [
            now()->startOfWeek()->toDateString(),
            now()->startOfWeek()->addDay()->toDateString(),
        ];

        $globalOptions = [];
        foreach ($dates as $index => $date) {
            $globalOptions[$date]['salad'] = CompanyFoodOption::query()->updateOrCreate(
                ['project_id' => $project->id, 'employee_list_id' => $primaryList->id, 'menu_date' => $date, 'category' => 'salad', 'name' => 'Help Garden Salad'],
                ['sort_order' => $index, 'is_active' => true],
            );
            $globalOptions[$date]['appetizer'] = CompanyFoodOption::query()->updateOrCreate(
                ['project_id' => $project->id, 'employee_list_id' => $primaryList->id, 'menu_date' => $date, 'category' => 'appetizer', 'name' => 'Help Hummus Cup'],
                ['sort_order' => $index, 'is_active' => true],
            );
            $globalOptions[$date]['sweet'] = CompanyFoodOption::query()->updateOrCreate(
                ['project_id' => $project->id, 'employee_list_id' => $primaryList->id, 'menu_date' => $date, 'category' => 'sweet', 'name' => 'Help Fruit Cup'],
                ['sort_order' => $index, 'is_active' => true],
            );
            $globalOptions[$date]['location'] = CompanyFoodOption::query()->updateOrCreate(
                ['project_id' => $project->id, 'employee_list_id' => $primaryList->id, 'menu_date' => $date, 'category' => 'location', 'name' => 'HQ Pantry'],
                ['sort_order' => $index, 'is_active' => true],
            );

            CompanyFoodOption::query()->updateOrCreate(
                ['project_id' => $project->id, 'employee_list_id' => $primaryList->id, 'menu_date' => $date, 'category' => 'main', 'name' => 'Head Office Chicken Plate'],
                ['sort_order' => $index, 'is_active' => true],
            );
            CompanyFoodOption::query()->updateOrCreate(
                ['project_id' => $project->id, 'employee_list_id' => $primaryList->id, 'menu_date' => $date, 'category' => 'soup', 'name' => 'Head Office Lentil Soup'],
                ['sort_order' => $index, 'is_active' => true],
            );
            CompanyFoodOption::query()->updateOrCreate(
                ['project_id' => $project->id, 'employee_list_id' => $secondaryList->id, 'menu_date' => $date, 'category' => 'main', 'name' => 'Factory Team Beef Plate'],
                ['sort_order' => $index, 'is_active' => true],
            );
            CompanyFoodOption::query()->updateOrCreate(
                ['project_id' => $project->id, 'employee_list_id' => $secondaryList->id, 'menu_date' => $date, 'category' => 'soup', 'name' => 'Factory Team Tomato Soup'],
                ['sort_order' => $index, 'is_active' => true],
            );
        }

        if (Schema::hasTable('company_food_orders')) {
            $orderDate = $dates[0];
            $salad = CompanyFoodOption::query()->where('project_id', $project->id)->where('menu_date', $orderDate)->where('category', 'salad')->value('id');
            $appetizer = CompanyFoodOption::query()->where('project_id', $project->id)->where('menu_date', $orderDate)->where('category', 'appetizer')->value('id');
            $main = CompanyFoodOption::query()->where('project_id', $project->id)->where('employee_list_id', $primaryList->id)->where('menu_date', $orderDate)->where('category', 'main')->value('id');
            $sweet = CompanyFoodOption::query()->where('project_id', $project->id)->where('menu_date', $orderDate)->where('category', 'sweet')->value('id');
            $location = CompanyFoodOption::query()->where('project_id', $project->id)->where('menu_date', $orderDate)->where('category', 'location')->value('id');
            $soup = CompanyFoodOption::query()->where('project_id', $project->id)->where('employee_list_id', $primaryList->id)->where('menu_date', $orderDate)->where('category', 'soup')->value('id');

            CompanyFoodOrder::query()->updateOrCreate(
                [
                    'project_id' => $project->id,
                    'employee_list_id' => $primaryList->id,
                    'order_date' => $orderDate,
                    'employee_name' => 'Help Employee One',
                ],
                [
                    'email' => 'employee.one@example.com',
                    'salad_option_id' => $salad,
                    'appetizer_option_id_1' => $appetizer,
                    'appetizer_option_id_2' => null,
                    'main_option_id' => $main,
                    'sweet_option_id' => $sweet,
                    'location_option_id' => $location,
                    'soup_option_id' => $soup,
                ],
            );
        }
    }

    private function syncCompanyFoodCategories(CompanyFoodEmployeeList $list): void
    {
        foreach (['salad', 'appetizer', 'soup', 'main', 'sweet', 'location'] as $index => $category) {
            CompanyFoodListCategory::query()->updateOrCreate(
                ['employee_list_id' => $list->id, 'category' => $category],
                ['sort_order' => $index],
            );
        }
    }
}

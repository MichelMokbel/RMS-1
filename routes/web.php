<?php

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
    Volt::route('daily-dish/menus/{branch}/{serviceDate}', 'daily-dish.menus.edit')->name('daily-dish.menus.edit');
});

Route::middleware(['auth', 'active', 'role:admin|manager'])->group(function () {
    Volt::route('meal-plan-requests', 'meal-plan-requests.index')->name('meal-plan-requests.index');
    Volt::route('meal-plan-requests/{mealPlanRequest}', 'meal-plan-requests.show')->name('meal-plan-requests.show');
});

Route::middleware(['auth', 'active', 'role:admin|manager|kitchen|cashier'])->group(function () {
    Volt::route('daily-dish/ops/{branch}/{date}', 'daily-dish.ops.day')->name('daily-dish.ops.day');
    Volt::route('kitchen/ops/{branch}/{date}', 'kitchen.ops')->name('kitchen.ops');
});

Route::middleware(['auth', 'active', 'role:admin|manager|cashier'])->group(function () {
    Volt::route('daily-dish/ops/{branch}/{date}/manual/create', 'daily-dish.ops.manual-create')->name('daily-dish.ops.manual.create');
});

Route::middleware(['auth', 'active', 'role:admin|manager'])->group(function () {
    Volt::route('subscriptions', 'subscriptions.index')->name('subscriptions.index');
    Volt::route('subscriptions/create', 'subscriptions.create')->name('subscriptions.create');
    Volt::route('subscriptions/{subscription}', 'subscriptions.show')->name('subscriptions.show');
    Volt::route('subscriptions/{subscription}/edit', 'subscriptions.edit')->name('subscriptions.edit');
    Volt::route('subscriptions/generate', 'subscriptions.generate')->name('subscriptions.generate');
});

Route::middleware(['auth', 'active', 'role:admin|manager|kitchen|cashier'])->group(function () {
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
    Volt::route('inventory/{item}', 'inventory.show')->name('inventory.show');
});

Route::middleware(['auth', 'active', 'role:admin|manager'])->group(function () {
    Volt::route('inventory/create', 'inventory.create')->name('inventory.create');
    Volt::route('inventory/{item}/edit', 'inventory.edit')->name('inventory.edit');
});

Route::middleware(['auth', 'active', 'role:admin|manager|cashier'])->group(function () {
    Volt::route('menu-items', 'menu-items.index')->name('menu-items.index');
});

Route::middleware(['auth', 'active', 'role:admin|manager'])->group(function () {
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

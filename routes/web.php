<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Laravel\Fortify\Features;

Route::view('/', 'welcome')->name('home');

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
});

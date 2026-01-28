<?php

use App\Http\Controllers\Api\AP\ApInvoiceController;
use App\Http\Controllers\Api\AP\ApPaymentController;
use App\Http\Controllers\Api\AP\ApReportsController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DailyDishMenuController;
use App\Http\Controllers\Api\PublicDailyDishController;
use App\Http\Controllers\Api\PublicDailyDishOrderController;
use App\Http\Controllers\Api\Expenses\ExpenseCategoryController;
use App\Http\Controllers\Api\Expenses\ExpenseController as ApiExpenseController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InventoryTransferController;
use App\Http\Controllers\Api\MealSubscriptionController;
use App\Http\Controllers\Api\MenuItemController;
use App\Http\Controllers\Api\PettyCash\ExpenseController as PettyCashExpenseController;
use App\Http\Controllers\Api\PettyCash\IssueController as PettyCashIssueController;
use App\Http\Controllers\Api\PettyCash\ReconciliationController as PettyCashReconciliationController;
use App\Http\Controllers\Api\PettyCash\WalletController as PettyCashWalletController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('daily-dish/menus', [DailyDishMenuController::class, 'index']);
    Route::get('daily-dish/menus/{menu}', [DailyDishMenuController::class, 'show']);
    Route::put('daily-dish/menus/{branchId}/{serviceDate}', [DailyDishMenuController::class, 'upsert']);
    Route::post('daily-dish/menus/{menu}/publish', [DailyDishMenuController::class, 'publish']);
    Route::post('daily-dish/menus/{menu}/unpublish', [DailyDishMenuController::class, 'unpublish']);
    Route::post('daily-dish/menus/{menu}/clone', [DailyDishMenuController::class, 'clone']);

    Route::get('subscriptions', [MealSubscriptionController::class, 'index']);
    Route::get('subscriptions/{subscription}', [MealSubscriptionController::class, 'show']);
    Route::post('subscriptions', [MealSubscriptionController::class, 'store']);
    Route::put('subscriptions/{subscription}', [MealSubscriptionController::class, 'update']);
    Route::post('subscriptions/{subscription}/pause', [MealSubscriptionController::class, 'pause']);
    Route::post('subscriptions/{subscription}/resume', [MealSubscriptionController::class, 'resume']);
    Route::post('subscriptions/{subscription}/cancel', [MealSubscriptionController::class, 'cancel']);
});

// Public endpoints for the external website form (no session/cookies)
Route::middleware(['api', 'throttle:60,1'])->prefix('public')->group(function () {
    Route::get('daily-dish/menus', [PublicDailyDishController::class, 'menus']);
    Route::post('daily-dish/orders', [PublicDailyDishOrderController::class, 'store'])->middleware('throttle:20,1');
});

$apiAuthMiddleware =  'auth';

Route::middleware(['api', $apiAuthMiddleware])->group(function () {
    Route::get('categories', [CategoryController::class, 'index'])->name('api.categories.index');

    Route::middleware(['role:admin|manager'])->group(function () {
        Route::post('categories', [CategoryController::class, 'store'])->name('api.categories.store');
        Route::put('categories/{category}', [CategoryController::class, 'update'])->name('api.categories.update');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('api.categories.destroy');
    });

    Route::get('suppliers', [SupplierController::class, 'index'])->name('api.suppliers.index');

    Route::middleware(['role:admin'])->group(function () {
        Route::post('suppliers', [SupplierController::class, 'store'])->name('api.suppliers.store');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('api.suppliers.update');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('api.suppliers.destroy');
    });

    Route::get('customers', [CustomerController::class, 'index'])->name('api.customers.index');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('api.customers.show');

    Route::middleware(['role:admin|manager'])->group(function () {
        Route::post('customers', [CustomerController::class, 'store'])->name('api.customers.store');
        Route::put('customers/{customer}', [CustomerController::class, 'update'])->name('api.customers.update');
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('api.customers.destroy');

        Route::post('inventory', [InventoryController::class, 'store'])->name('api.inventory.store');
        Route::put('inventory/{item}', [InventoryController::class, 'update'])->name('api.inventory.update');
        Route::post('inventory/{item}/adjustments', [InventoryController::class, 'adjust'])->name('api.inventory.adjust');
        Route::post('inventory/{item}/availability', [InventoryController::class, 'addAvailability'])->name('api.inventory.availability');
        Route::post('inventory/transfers', [InventoryTransferController::class, 'store'])->name('api.inventory.transfers.store');

        Route::post('menu-items', [MenuItemController::class, 'store'])->name('api.menu-items.store');
        Route::put('menu-items/{menuItem}', [MenuItemController::class, 'update'])->name('api.menu-items.update');
        Route::delete('menu-items/{menuItem}', [MenuItemController::class, 'destroy'])->name('api.menu-items.destroy');

        Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('api.purchase-orders.store');
        Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('api.purchase-orders.update');
        Route::post('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->name('api.purchase-orders.submit');
        Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->name('api.purchase-orders.approve');
        Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('api.purchase-orders.receive');
        Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('api.purchase-orders.cancel');

        Route::post('ap/invoices', [ApInvoiceController::class, 'store'])->name('api.ap.invoices.store');
        Route::put('ap/invoices/{invoice}', [ApInvoiceController::class, 'update'])->name('api.ap.invoices.update');
        Route::post('ap/invoices/{invoice}/post', [ApInvoiceController::class, 'post'])->name('api.ap.invoices.post');
        Route::post('ap/invoices/{invoice}/void', [ApInvoiceController::class, 'void'])->name('api.ap.invoices.void');

        Route::post('ap/payments', [ApPaymentController::class, 'store'])->name('api.ap.payments.store');
    });

    Route::get('inventory', [InventoryController::class, 'index'])->name('api.inventory.index');
    Route::get('inventory/{item}', [InventoryController::class, 'show'])->name('api.inventory.show');

    Route::get('menu-items', [MenuItemController::class, 'index'])->name('api.menu-items.index');
    Route::get('menu-items/{menuItem}', [MenuItemController::class, 'show'])->name('api.menu-items.show');

    Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('api.purchase-orders.index');
    Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('api.purchase-orders.show');

    Route::get('ap/invoices', [ApInvoiceController::class, 'index'])->name('api.ap.invoices.index');
    Route::get('ap/invoices/{invoice}', [ApInvoiceController::class, 'show'])->name('api.ap.invoices.show');
    Route::get('ap/payments', [ApPaymentController::class, 'index'])->name('api.ap.payments.index');
    Route::get('ap/payments/{payment}', [ApPaymentController::class, 'show'])->name('api.ap.payments.show');
    Route::get('ap/aging', [ApReportsController::class, 'aging'])->name('api.ap.aging');

    // Expenses
    Route::get('expenses', [ApiExpenseController::class, 'index'])->name('api.expenses.index');
    Route::get('expenses/{expense}', [ApiExpenseController::class, 'show'])->name('api.expenses.show');
    Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->name('api.expense-categories.index');

    Route::middleware(['role:admin|manager'])->group(function () {
        Route::post('expenses', [ApiExpenseController::class, 'store'])->name('api.expenses.store');
        Route::put('expenses/{expense}', [ApiExpenseController::class, 'update'])->name('api.expenses.update');
        Route::delete('expenses/{expense}', [ApiExpenseController::class, 'destroy'])->name('api.expenses.destroy');
        Route::post('expenses/{expense}/payments', [ApiExpenseController::class, 'storePayment'])->name('api.expenses.payments.store');
        Route::post('expenses/{expense}/attachments', [ApiExpenseController::class, 'storeAttachment'])->name('api.expenses.attachments.store');
        Route::delete('expense-attachments/{attachment}', [ApiExpenseController::class, 'destroyAttachment'])->name('api.expenses.attachments.destroy');

        Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->name('api.expense-categories.store');
        Route::put('expense-categories/{category}', [ExpenseCategoryController::class, 'update'])->name('api.expense-categories.update');
        Route::delete('expense-categories/{category}', [ExpenseCategoryController::class, 'destroy'])->name('api.expense-categories.destroy');
    });

    // Petty cash (auth + role)
    Route::middleware(['role:admin|manager|staff'])->group(function () {
        Route::get('petty-cash/wallets', [PettyCashWalletController::class, 'index'])->name('api.petty-cash.wallets.index');
        Route::get('petty-cash/issues', [PettyCashIssueController::class, 'index'])->name('api.petty-cash.issues.index');
        Route::get('petty-cash/expenses', [PettyCashExpenseController::class, 'index'])->name('api.petty-cash.expenses.index');
    });

    Route::middleware(['role:admin|manager'])->group(function () {
        Route::post('petty-cash/wallets', [PettyCashWalletController::class, 'store'])->name('api.petty-cash.wallets.store');
        Route::put('petty-cash/wallets/{id}', [PettyCashWalletController::class, 'update'])->name('api.petty-cash.wallets.update');

        Route::post('petty-cash/issues', [PettyCashIssueController::class, 'store'])->name('api.petty-cash.issues.store');

        Route::post('petty-cash/reconciliations', [PettyCashReconciliationController::class, 'store'])->name('api.petty-cash.reconciliations.store');
    });

    Route::middleware(['role:admin|manager|staff'])->group(function () {
        Route::post('petty-cash/expenses', [PettyCashExpenseController::class, 'store'])->name('api.petty-cash.expenses.store');
        Route::post('petty-cash/expenses/{id}', [PettyCashExpenseController::class, 'update'])->name('api.petty-cash.expenses.update');
    });
});

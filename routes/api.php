<?php

use App\Http\Controllers\Api\AP\ApInvoiceController;
use App\Http\Controllers\Api\AP\ApPaymentController;
use App\Http\Controllers\Api\AP\ApReportsController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DailyDishMenuController;
use App\Http\Controllers\Api\PublicCompanyFoodController;
use App\Http\Controllers\Api\PublicCompanyFoodOrderController;
use App\Http\Controllers\Api\PublicDailyDishController;
use App\Http\Controllers\Api\PublicDailyDishOrderController;
use App\Http\Controllers\Api\Expenses\ExpenseCategoryController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InventoryTransactionController;
use App\Http\Controllers\Api\InventoryTransferController;
use App\Http\Controllers\Api\MealSubscriptionController;
use App\Http\Controllers\Api\MenuItemController;
use App\Http\Controllers\Api\PettyCash\IssueController as PettyCashIssueController;
use App\Http\Controllers\Api\PettyCash\ReconciliationController as PettyCashReconciliationController;
use App\Http\Controllers\Api\PettyCash\WalletController as PettyCashWalletController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\Pos\AuthController as PosAuthController;
use App\Http\Controllers\Api\Pos\BootstrapController as PosBootstrapController;
use App\Http\Controllers\Api\Pos\PrintJobController as PosPrintJobController;
use App\Http\Controllers\Api\Pos\PrintTerminalStatusController as PosPrintTerminalStatusController;
use App\Http\Controllers\Api\Pos\SequenceController as PosSequenceController;
use App\Http\Controllers\Api\Pos\SyncController as PosSyncController;
use App\Http\Controllers\Api\Spend\ExpenseController as SpendExpenseController;
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
Route::middleware('api')->prefix('public')->group(function () {
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('daily-dish/menus', [PublicDailyDishController::class, 'menus']);
        Route::post('daily-dish/orders', [PublicDailyDishOrderController::class, 'store'])->middleware('throttle:20,1');
    });

    // Company Food (standalone module - no integration with orders/daily-dish)
    // Intentionally unthrottled per current integration requirement.
    Route::prefix('company-food/{projectSlug}')->group(function () {
        Route::get('options', [PublicCompanyFoodController::class, 'options']);
        Route::get('orders', [PublicCompanyFoodOrderController::class, 'index']);
        Route::post('orders', [PublicCompanyFoodOrderController::class, 'store']);
        Route::get('orders/{id}', [PublicCompanyFoodOrderController::class, 'show']);
        Route::put('orders/{id}', [PublicCompanyFoodOrderController::class, 'update']);
    });
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
        Route::post('inventory/transactions', [InventoryTransactionController::class, 'store'])->name('api.inventory.transactions.store');
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

    // Spend (AP expense invoices)
    Route::get('spend/expenses', [SpendExpenseController::class, 'index'])->name('api.spend.expenses.index');
    Route::get('spend/expenses/{invoice}', [SpendExpenseController::class, 'show'])->name('api.spend.expenses.show');

    // Legacy expenses API is cut over to Spend AP expense flow.
    Route::get('expenses', fn () => response()->json([
        'message' => __('Legacy expenses API is deprecated. Use /api/spend/expenses.'),
    ], 410))->name('api.expenses.index');
    Route::get('expenses/{expense}', fn () => response()->json([
        'message' => __('Legacy expenses API is deprecated. Use /api/spend/expenses/{invoice}.'),
    ], 410))->name('api.expenses.show');
    Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->name('api.expense-categories.index');

    Route::middleware(['role:admin|manager'])->group(function () {
        Route::post('expenses', fn () => response()->json([
            'message' => __('Legacy expenses API is deprecated. Use /api/spend/expenses.'),
        ], 410))->name('api.expenses.store');
        Route::put('expenses/{expense}', fn () => response()->json([
            'message' => __('Legacy expenses API is deprecated. Use /api/spend/expenses/{invoice}.'),
        ], 410))->name('api.expenses.update');
        Route::delete('expenses/{expense}', fn () => response()->json([
            'message' => __('Legacy expenses API is deprecated.'),
        ], 410))->name('api.expenses.destroy');
        Route::post('expenses/{expense}/payments', fn () => response()->json([
            'message' => __('Legacy expenses API is deprecated. Use /api/spend/expenses/{invoice}/settle.'),
        ], 410))->name('api.expenses.payments.store');
        Route::post('expenses/{expense}/attachments', fn () => response()->json([
            'message' => __('Legacy expenses API is deprecated. Use /api/spend/expenses/{invoice}/attachments.'),
        ], 410))->name('api.expenses.attachments.store');
        Route::delete('expense-attachments/{attachment}', fn () => response()->json([
            'message' => __('Legacy expenses API is deprecated.'),
        ], 410))->name('api.expenses.attachments.destroy');

        Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->name('api.expense-categories.store');
        Route::put('expense-categories/{category}', [ExpenseCategoryController::class, 'update'])->name('api.expense-categories.update');
        Route::delete('expense-categories/{category}', [ExpenseCategoryController::class, 'destroy'])->name('api.expense-categories.destroy');
    });

    // Petty cash (auth + role)
    Route::middleware(['role:admin|manager|staff'])->group(function () {
        Route::get('petty-cash/wallets', [PettyCashWalletController::class, 'index'])->name('api.petty-cash.wallets.index');
        Route::get('petty-cash/issues', [PettyCashIssueController::class, 'index'])->name('api.petty-cash.issues.index');
    });

    Route::middleware(['role:admin|manager'])->group(function () {
        Route::post('petty-cash/wallets', [PettyCashWalletController::class, 'store'])->name('api.petty-cash.wallets.store');
        Route::put('petty-cash/wallets/{id}', [PettyCashWalletController::class, 'update'])->name('api.petty-cash.wallets.update');

        Route::post('petty-cash/issues', [PettyCashIssueController::class, 'store'])->name('api.petty-cash.issues.store');

        Route::post('petty-cash/reconciliations', [PettyCashReconciliationController::class, 'store'])->name('api.petty-cash.reconciliations.store');
    });

    Route::middleware(['role:admin|manager|staff'])->group(function () {
        Route::post('spend/expenses', [SpendExpenseController::class, 'store'])->name('api.spend.expenses.store');
        Route::post('spend/expenses/{invoice}/submit', [SpendExpenseController::class, 'submit'])->name('api.spend.expenses.submit');
        Route::post('spend/expenses/{invoice}/attachments', [SpendExpenseController::class, 'storeAttachment'])->name('api.spend.expenses.attachments.store');
    });

    Route::middleware(['role_or_permission:admin|manager|finance.access'])->group(function () {
        Route::post('spend/expenses/{invoice}/approve', [SpendExpenseController::class, 'approve'])->name('api.spend.expenses.approve');
        Route::post('spend/expenses/{invoice}/reject', [SpendExpenseController::class, 'reject'])->name('api.spend.expenses.reject');
    });

    Route::middleware(['role_or_permission:admin|finance.access'])->group(function () {
        Route::post('spend/expenses/{invoice}/post', [SpendExpenseController::class, 'post'])->name('api.spend.expenses.post');
        Route::post('spend/expenses/{invoice}/settle', [SpendExpenseController::class, 'settle'])->name('api.spend.expenses.settle');
    });

});

// POS (Flutter desktop) - offline-first endpoints.
Route::prefix('pos')->group(function () {
    Route::post('setup/branches', [PosAuthController::class, 'branches']);
    Route::post('setup/terminals/register', [PosAuthController::class, 'registerTerminal']);
    Route::post('login', [PosAuthController::class, 'login']);

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('print-terminals/{terminal_code}/status', [PosPrintTerminalStatusController::class, 'show']);

        Route::middleware(['pos.token'])->group(function () {
            Route::post('logout', [PosAuthController::class, 'logout']);
            Route::get('bootstrap', PosBootstrapController::class);
            Route::post('sequences/reserve', [PosSequenceController::class, 'reserve']);
            Route::post('sync', PosSyncController::class);

            Route::post('print-jobs', [PosPrintJobController::class, 'store']);
            Route::get('print-jobs/stream', [PosPrintJobController::class, 'stream']);
            Route::get('print-jobs/pull', [PosPrintJobController::class, 'pull']);
            Route::post('print-jobs/{job_id}/ack', [PosPrintJobController::class, 'ack'])->whereNumber('job_id');
        });
    });
});

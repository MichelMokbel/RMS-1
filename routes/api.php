<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MenuItemController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\AP\ApInvoiceController;
use App\Http\Controllers\Api\AP\ApPaymentController;
use App\Http\Controllers\Api\AP\ApReportsController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->group(function () {
    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store']);
    Route::put('categories/{category}', [CategoryController::class, 'update']);
    Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
});

$apiAuthMiddleware =  'auth';

Route::middleware(['api', $apiAuthMiddleware])->group(function () {
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
});

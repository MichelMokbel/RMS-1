<?php

namespace App\Providers;

use App\Models\ArInvoice;
use App\Models\PosShift;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('pos.openShift', fn (User $user, int $branchId = 1) => $user->hasAnyRole(['admin', 'manager', 'cashier']));
        Gate::define('pos.closeShift', fn (User $user, ?PosShift $shift = null) => $user->hasAnyRole(['admin', 'manager']));

        Gate::define('sale.checkout', fn (User $user, ?Sale $sale = null) => $user->hasAnyRole(['admin', 'manager', 'cashier']));
        Gate::define('sale.void', fn (User $user, ?Sale $sale = null) => $user->hasAnyRole(['admin', 'manager']));

        Gate::define('ar.invoice.issue', fn (User $user, ?ArInvoice $invoice = null) => $user->hasAnyRole(['admin', 'manager']));
        Gate::define('ar.invoice.applyPayment', fn (User $user, ?ArInvoice $invoice = null) => $user->hasAnyRole(['admin', 'manager']));
        Gate::define('ar.invoice.creditNote', fn (User $user, ?ArInvoice $invoice = null) => $user->hasAnyRole(['admin', 'manager']));
    }
}


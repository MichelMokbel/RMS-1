<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyReportDateDefaults
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $defaults = [];

        if (! $request->filled('date_from')) {
            $defaults['date_from'] = $monthStart;
        }
        if (! $request->filled('date_to')) {
            $defaults['date_to'] = $monthEnd;
        }

        if (! $request->filled('invoice_date_from')) {
            $defaults['invoice_date_from'] = $monthStart;
        }
        if (! $request->filled('invoice_date_to')) {
            $defaults['invoice_date_to'] = $monthEnd;
        }

        if (! $request->filled('payment_date_from')) {
            $defaults['payment_date_from'] = $monthStart;
        }
        if (! $request->filled('payment_date_to')) {
            $defaults['payment_date_to'] = $monthEnd;
        }

        if ($defaults !== []) {
            $request->merge($defaults);
        }

        return $next($request);
    }
}

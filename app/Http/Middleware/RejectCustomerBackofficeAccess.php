<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectCustomerBackofficeAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('customer')) {
            return $next($request);
        }

        if ($request->routeIs('logout') || $request->isMethod('post') && $request->is('logout')) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'Backoffice access is not available for customer accounts.'], 403);
        }

        abort(403, 'Backoffice access is not available for customer accounts.');
    }
}

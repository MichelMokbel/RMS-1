<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVerifiedCustomerPhone
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user()?->customer;

        if (! $customer || $customer->phone_verified_at === null) {
            return response()->json([
                'message' => 'Phone verification is required.',
                'code' => 'PHONE_NOT_VERIFIED',
            ], 403);
        }

        return $next($request);
    }
}

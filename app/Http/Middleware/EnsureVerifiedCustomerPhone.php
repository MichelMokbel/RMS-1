<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVerifiedCustomerPhone
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $customerId = (int) ($user?->customer_id ?? 0);
        $customer = $customerId > 0
            ? Customer::query()->select(['id', 'phone_verified_at'])->find($customerId)
            : null;

        if (! $customer || $customer->phone_verified_at === null) {
            return response()->json([
                'message' => 'Phone verification is required.',
                'code' => 'PHONE_NOT_VERIFIED',
            ], 403);
        }

        return $next($request);
    }
}

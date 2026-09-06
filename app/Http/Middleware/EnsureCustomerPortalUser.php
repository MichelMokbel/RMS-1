<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerPortalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isCustomerPortalUser()) {
            return response()->json(['message' => 'Customer portal access is required.'], 403);
        }

        if (! $user->isActive()) {
            return response()->json([
                'message' => 'This customer account is inactive. Sign in with the surviving account or contact support.',
                'code' => 'CUSTOMER_ACCOUNT_INACTIVE',
            ], 403);
        }

        if (! $user->currentAccessToken() || ! $user->tokenCan('customer:*')) {
            return response()->json(['message' => 'Customer portal token is required.'], 403);
        }

        return $next($request);
    }
}

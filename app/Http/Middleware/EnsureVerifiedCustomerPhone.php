<?php

namespace App\Http\Middleware;

use App\Services\Customers\CustomerPortalAccountService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVerifiedCustomerPhone
{
    public function __construct(
        private readonly CustomerPortalAccountService $accounts,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $this->accounts->isPhoneVerified($user)) {
            return response()->json([
                'message' => 'Phone verification is required.',
                'code' => 'PHONE_NOT_VERIFIED',
            ], 403);
        }

        return $next($request);
    }
}

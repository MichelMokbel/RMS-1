<?php

namespace App\Http\Middleware;

use App\Services\Security\BranchAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBranchAccess
{
    public function __construct(
        private readonly BranchAccessService $branchAccess,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->isAdmin()) {
            return $next($request);
        }

        if ($this->shouldBypassForPublicCustomerOrder($request)) {
            return $next($request);
        }

        $requestedBranchIds = $this->branchAccess->requestedBranchIds($request);
        foreach ($requestedBranchIds as $branchId) {
            if (! $this->branchAccess->canAccessBranch($user, (int) $branchId)) {
                if ($request->is('api/*') || $request->expectsJson()) {
                    return response()->json([
                        'message' => 'This account does not have access to the requested branch.',
                        'code' => 'BRANCH_ACCESS_DENIED',
                    ], 403);
                }

                abort(403);
            }
        }

        return $next($request);
    }

    private function shouldBypassForPublicCustomerOrder(Request $request): bool
    {
        $user = $request->user();

        return $user?->isCustomerPortalUser()
            && $request->isMethod('post')
            && $request->is('api/public/daily-dish/orders');
    }
}

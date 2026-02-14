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

        $requestedBranchIds = $this->branchAccess->requestedBranchIds($request);
        foreach ($requestedBranchIds as $branchId) {
            if (! $this->branchAccess->canAccessBranch($user, (int) $branchId)) {
                abort(403);
            }
        }

        return $next($request);
    }
}


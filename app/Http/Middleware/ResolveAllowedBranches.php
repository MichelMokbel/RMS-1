<?php

namespace App\Http\Middleware;

use App\Services\Security\BranchAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveAllowedBranches
{
    public function __construct(
        private readonly BranchAccessService $branchAccess,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $request->attributes->set('allowed_branch_ids', $this->branchAccess->allowedBranchIds($user));
        }

        return $next($request);
    }
}


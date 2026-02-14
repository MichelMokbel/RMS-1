<?php

namespace App\Http\Middleware;

use App\Services\Security\BranchAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EnsureActiveBranch
{
    public function __construct(
        private readonly BranchAccessService $branchAccess,
    ) {
    }

    public function handle(Request $request, Closure $next, string $paramName = 'branch')
    {
        if (! Schema::hasTable('branches')) {
            return $next($request);
        }

        $raw = $request->route($paramName);
        if ($raw === null) {
            $raw = $request->route('branch');
        }

        $branchId = (int) $raw;
        if ($branchId <= 0) {
            abort(404);
        }

        $q = DB::table('branches')->where('id', $branchId);
        if (Schema::hasColumn('branches', 'is_active')) {
            $q->where('is_active', 1);
        }

        if (! $q->exists()) {
            abort(404);
        }

        $user = $request->user();
        if ($user && ! $user->isAdmin() && ! $this->branchAccess->canAccessBranch($user, $branchId)) {
            abort(403);
        }

        return $next($request);
    }
}

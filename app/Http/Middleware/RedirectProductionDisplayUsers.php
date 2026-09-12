<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectProductionDisplayUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->hasAnyRole(['admin', 'manager'])) {
            return $next($request);
        }

        $branchId = $user->allowedBranchIds()[0] ?? null;

        if ($user->hasRole('kitchen') || $user->can('kitchen.display')) {
            abort_unless($branchId, 403, __('No active branch is assigned to this account.'));

            return redirect()->route('kitchen.ops', [$branchId, now()->toDateString()]);
        }

        if ($user->hasRole('pastry-user') || $user->can('pastry.display')) {
            abort_unless($branchId, 403, __('No active branch is assigned to this account.'));

            return redirect()->route('pastry-orders.display', [$branchId, now()->toDateString()]);
        }

        return $next($request);
    }
}

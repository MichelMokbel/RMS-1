<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SafetyPolicyService
{
    /**
     * @param  array<int, string>  $nextRoles
     */
    public function assertAdminSafety(User $target, array $nextRoles, string $nextStatus): void
    {
        $currentRoles = $target->getRoleNames()->map(fn ($r) => (string) $r)->all();
        $targetIsAdminNow = in_array('admin', $currentRoles, true);
        $targetWillBeAdmin = in_array('admin', $nextRoles, true);
        $targetWillBeActive = $nextStatus === 'active';

        if (! $targetIsAdminNow) {
            return;
        }

        if ($targetWillBeAdmin && $targetWillBeActive) {
            return;
        }

        $otherActiveAdminCount = DB::table('users as u')
            ->join('model_has_roles as mhr', function ($join): void {
                $join->on('mhr.model_id', '=', 'u.id')
                    ->where('mhr.model_type', User::class);
            })
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->where('r.name', 'admin')
            ->where('r.guard_name', 'web')
            ->where('u.status', 'active')
            ->where('u.id', '!=', (int) $target->id)
            ->distinct('u.id')
            ->count('u.id');

        if ($otherActiveAdminCount <= 0) {
            throw ValidationException::withMessages([
                'roles' => __('Cannot remove or deactivate the last active admin.'),
            ]);
        }
    }

    /**
     * @param  array<int, string>  $nextRoles
     * @param  array<int, string>  $nextPermissions
     */
    public function assertNoSelfLockout(User $actor, User $target, array $nextRoles, array $nextPermissions, string $nextStatus): void
    {
        if ((int) $actor->id !== (int) $target->id) {
            return;
        }

        if ($this->canManageIam($nextRoles, $nextPermissions, $nextStatus)) {
            return;
        }

        throw ValidationException::withMessages([
            'roles' => __('You cannot remove your own IAM access.'),
        ]);
    }

    /**
     * @param  array<int, string>  $roles
     * @param  array<int, string>  $permissions
     */
    private function canManageIam(array $roles, array $permissions, string $status): bool
    {
        if ($status !== 'active') {
            return false;
        }

        if (in_array('admin', $roles, true)) {
            return true;
        }

        return in_array('iam.users.manage', $permissions, true);
    }
}


<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class IamUserService
{
    public function __construct(
        private readonly SafetyPolicyService $safety,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, User $actor): User
    {
        $roles = $this->normalizeRoleNames((array) ($payload['roles'] ?? []));
        $permissions = $this->normalizePermissionNames((array) ($payload['permissions'] ?? []));
        $branchIds = $this->normalizeBranchIds((array) ($payload['branch_ids'] ?? []));

        return DB::transaction(function () use ($payload, $roles, $permissions, $branchIds): User {
            $user = User::create([
                'name' => Str::headline((string) $payload['username']),
                'username' => Str::lower((string) $payload['username']),
                'email' => Str::lower((string) $payload['email']),
                'password' => (string) $payload['password'],
                'status' => (string) $payload['status'],
                'pos_enabled' => (bool) ($payload['pos_enabled'] ?? false),
            ]);

            $user->syncRoles($roles);
            $user->syncPermissions($permissions);
            $this->syncBranchAccess($user, $roles, $branchIds);

            return $user->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(User $target, array $payload, User $actor): User
    {
        $nextRoles = array_key_exists('roles', $payload)
            ? $this->normalizeRoleNames((array) ($payload['roles'] ?? []))
            : $target->getRoleNames()->map(fn ($r) => (string) $r)->all();

        $nextPermissions = array_key_exists('permissions', $payload)
            ? $this->normalizePermissionNames((array) ($payload['permissions'] ?? []))
            : $target->getDirectPermissions()->pluck('name')->map(fn ($p) => (string) $p)->all();

        $nextStatus = (string) ($payload['status'] ?? $target->status);

        $this->safety->assertAdminSafety($target, $nextRoles, $nextStatus);
        $this->safety->assertNoSelfLockout($actor, $target, $nextRoles, $nextPermissions, $nextStatus);

        $branchIds = array_key_exists('branch_ids', $payload)
            ? $this->normalizeBranchIds((array) ($payload['branch_ids'] ?? []))
            : $target->allowedBranchIds();

        return DB::transaction(function () use ($target, $payload, $nextRoles, $nextPermissions, $branchIds): User {
            $target->forceFill([
                'name' => Str::headline((string) ($payload['username'] ?? $target->username)),
                'username' => Str::lower((string) ($payload['username'] ?? $target->username)),
                'email' => Str::lower((string) ($payload['email'] ?? $target->email)),
                'status' => (string) ($payload['status'] ?? $target->status),
                'pos_enabled' => (bool) ($payload['pos_enabled'] ?? $target->pos_enabled),
            ]);

            if (isset($payload['password']) && trim((string) $payload['password']) !== '') {
                $target->password = (string) $payload['password'];
            }
            $target->save();

            $target->syncRoles($nextRoles);
            $target->syncPermissions($nextPermissions);
            $this->syncBranchAccess($target, $nextRoles, $branchIds);

            return $target->fresh();
        });
    }

    /**
     * @param  array<int, string>  $assignedRoles
     * @param  array<int, int>  $branchIds
     */
    private function syncBranchAccess(User $user, array $assignedRoles, array $branchIds): void
    {
        if (! Schema::hasTable('user_branch_access')) {
            return;
        }

        if (in_array('admin', $assignedRoles, true)) {
            DB::table('user_branch_access')->where('user_id', (int) $user->id)->delete();
            return;
        }

        $syncRows = collect($branchIds)
            ->unique()
            ->mapWithKeys(fn ($id) => [(int) $id => ['created_at' => now(), 'updated_at' => now()]])
            ->all();

        $user->branches()->sync($syncRows);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizeRoleNames(array $values): array
    {
        $names = collect($values)
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn ($v) => trim((string) $v))
            ->unique()
            ->values()
            ->all();

        if ($names === []) {
            return [];
        }

        return Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $names)
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizePermissionNames(array $values): array
    {
        $names = collect($values)
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn ($v) => trim((string) $v))
            ->unique()
            ->values()
            ->all();

        if ($names === []) {
            return [];
        }

        return Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $names)
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->all();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, int>
     */
    private function normalizeBranchIds(array $values): array
    {
        $ids = collect($values)
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $query = DB::table('branches')->whereIn('id', $ids);
        if (Schema::hasColumn('branches', 'is_active')) {
            $query->where('is_active', 1);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}


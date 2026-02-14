<?php

use Illuminate\Validation\Rule;
use App\Services\Security\RolePermissionService;
use Livewire\Volt\Component;
use Livewire\Volt\Layout;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $editing_role_id = null;
    /** @var array<int, string> */
    public array $editing_permissions = [];

    public function with(): array
    {
        return [
            'roles' => Role::query()
                ->where('guard_name', 'web')
                ->withCount('users')
                ->with('permissions:id,name')
                ->orderBy('name')
                ->get(),
            'permissionOptions' => Permission::query()
                ->where('guard_name', 'web')
                ->orderBy('name')
                ->pluck('name')
                ->all(),
            'editingRole' => $this->editing_role_id
                ? Role::query()->where('guard_name', 'web')->with('permissions:id,name')->find($this->editing_role_id)
                : null,
        ];
    }

    public function editRole(int $roleId): void
    {
        $role = Role::query()->where('guard_name', 'web')->findOrFail($roleId);
        $this->editing_role_id = (int) $role->id;
        $this->editing_permissions = $role->permissions->pluck('name')->map(fn ($name) => (string) $name)->all();
    }

    public function savePermissions(): void
    {
        $this->authorizeRoleManagement();

        $validated = $this->validate([
            'editing_role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'editing_permissions' => ['array'],
            'editing_permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ]);

        $role = Role::query()->where('guard_name', 'web')->findOrFail((int) $validated['editing_role_id']);
        /** @var \App\Models\User $actor */
        $actor = auth()->user();
        app(RolePermissionService::class)->updateRolePermissions($actor, $role, (array) $validated['editing_permissions']);
        session()->flash('status', __('Role permissions updated.'));
    }

    private function authorizeRoleManagement(): void
    {
        $user = auth()->user();
        if (! $user || (! $user->hasRole('admin') && ! $user->can('iam.roles.manage'))) {
            abort(403);
        }
    }
}; ?>

<div class="w-full space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('IAM Roles') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('View role memberships and edit role permissions.') }}</p>
        </div>
        <flux:button :href="route('iam.users.index')" wire:navigate variant="ghost">{{ __('Back to IAM') }}</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
            <thead class="bg-neutral-50 dark:bg-neutral-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{{ __('Role') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{{ __('Users') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{{ __('Permissions') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{{ __('Action') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-700 dark:bg-neutral-900">
                @forelse($roles as $role)
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $role->name }}</td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $role->users_count }}</td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            <div class="flex flex-wrap gap-1">
                                @forelse($role->permissions as $permission)
                                    <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ $permission->name }}</span>
                                @empty
                                    <span class="text-xs text-neutral-500">{{ __('None') }}</span>
                                @endforelse
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <flux:button size="xs" variant="ghost" wire:click="editRole({{ $role->id }})">{{ __('Edit Permissions') }}</flux:button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-sm text-center text-neutral-500">{{ __('No roles found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($editingRole)
        <div class="rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
            <div class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Edit Permissions for Role: :role', ['role' => $editingRole->name]) }}
            </div>
            <div class="grid gap-2">
                <select wire:model="editing_permissions" multiple class="min-h-[200px] rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                    @foreach($permissionOptions as $permissionName)
                        <option value="{{ $permissionName }}">{{ $permissionName }}</option>
                    @endforeach
                </select>
                @error('editing_permissions.*') <div class="text-xs text-red-600">{{ $message }}</div> @enderror
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('editing_role_id', null)">{{ __('Close') }}</flux:button>
                <flux:button variant="primary" wire:click="savePermissions">{{ __('Save Permissions') }}</flux:button>
            </div>
        </div>
    @endif
</div>

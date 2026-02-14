<?php

use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Livewire\Volt\Layout;
use Spatie\Permission\Models\Permission;

new #[Layout('components.layouts.app')] class extends Component {
    public function with(): array
    {
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get();

        $directCounts = DB::table('model_has_permissions')
            ->select('permission_id', DB::raw('count(*) as direct_count'))
            ->where('model_type', \App\Models\User::class)
            ->groupBy('permission_id')
            ->pluck('direct_count', 'permission_id');

        return [
            'permissions' => $permissions->map(function (Permission $permission) use ($directCounts) {
                return [
                    'id' => (int) $permission->id,
                    'name' => (string) $permission->name,
                    'roles_count' => $permission->roles()->count(),
                    'direct_users_count' => (int) ($directCounts[$permission->id] ?? 0),
                ];
            })->values(),
        ];
    }
}; ?>

<div class="w-full space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('IAM Permissions') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Permission catalog and assignment coverage.') }}</p>
        </div>
        <flux:button :href="route('iam.users.index')" wire:navigate variant="ghost">{{ __('Back to IAM') }}</flux:button>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
            <thead class="bg-neutral-50 dark:bg-neutral-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{{ __('Permission') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{{ __('Roles Using It') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">{{ __('Direct User Assignments') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-700 dark:bg-neutral-900">
                @forelse($permissions as $permission)
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $permission['name'] }}</td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $permission['roles_count'] }}</td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">{{ $permission['direct_users_count'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-6 text-sm text-center text-neutral-500">{{ __('No permissions found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>


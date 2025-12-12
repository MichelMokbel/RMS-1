<?php

use App\Models\User;
use Livewire\Volt\Component;
use Livewire\Volt\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public string $search = '';
    public string $status = 'all';

    /**
     * Provide the filtered users to the view.
     */
    public function with(): array
    {
        return [
            'users' => $this->query()->get(),
        ];
    }

    /**
     * Toggle a user's active status.
     */
    public function toggleStatus(int $userId): void
    {
        $user = User::findOrFail($userId);

        $user->status = $user->status === 'active' ? 'inactive' : 'active';
        $user->save();
    }

    /**
     * Build the base query for users.
     */
    private function query()
    {
        return User::query()
            ->when($this->search, function ($query): void {
                $query->where(function ($inner): void {
                    $inner
                        ->where('username', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->orderBy('username');
    }
}; ?>

<div class="w-full space-y-6">
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div class="flex flex-1 flex-col gap-3 md:flex-row md:items-center">
            <flux:input
                wire:model.debounce.400ms="search"
                placeholder="{{ __('Search by username or email') }}"
                class="w-full md:max-w-md"
            />

            <div class="flex items-center gap-2">
                <label for="status" class="text-sm text-zinc-600 dark:text-zinc-300">{{ __('Status') }}</label>
                <select
                    id="status"
                    wire:model="status"
                    class="rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                >
                    <option value="all">{{ __('All') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                </select>
            </div>
        </div>

        <div>
            <flux:button :href="route('users.create')" wire:navigate variant="primary">
                {{ __('Create User') }}
            </flux:button>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700">
            <thead class="bg-neutral-50 dark:bg-neutral-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">
                        {{ __('Username') }}
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">
                        {{ __('Email') }}
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">
                        {{ __('Status') }}
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500">
                        {{ __('Actions') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-700 dark:bg-neutral-900">
                @forelse ($users as $user)
                    <tr>
                        <td class="px-4 py-3 text-sm font-medium text-neutral-900 dark:text-neutral-100">
                            {{ $user->username }}
                        </td>
                        <td class="px-4 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                            {{ $user->email }}
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <span
                                class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold
                                {{ $user->status === 'active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' }}"
                            >
                                {{ ucfirst($user->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm space-x-2 rtl:space-x-reverse">
                            <flux:button size="xs" :href="route('users.edit', $user)" wire:navigate>
                                {{ __('Edit') }}
                            </flux:button>

                            <flux:button
                                size="xs"
                                variant="{{ $user->status === 'active' ? 'danger' : 'primary' }}"
                                wire:click="toggleStatus({{ $user->id }})"
                            >
                                {{ $user->status === 'active' ? __('Deactivate') : __('Activate') }}
                            </flux:button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                            {{ __('No users found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

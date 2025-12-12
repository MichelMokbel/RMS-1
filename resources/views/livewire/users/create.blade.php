<?php

use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Illuminate\Support\Str;
use Livewire\Volt\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public string $username = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $status = 'active';

    /**
     * Create a new user.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'username' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique(User::class)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        User::create([
            'name' => Str::headline($validated['username']),
            'username' => Str::lower($validated['username']),
            'email' => Str::lower($validated['email']),
            'password' => $validated['password'],
            'status' => $validated['status'],
        ]);

        session()->flash('status', __('User created successfully.'));

        $this->redirectRoute('users.index');
    }
}; ?>

<div class="w-full space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Create User') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Add a new user to the system.') }}</p>
        </div>

        <flux:button :href="route('users.index')" wire:navigate variant="ghost">
            {{ __('Back to users') }}
        </flux:button>
    </div>

    <form wire:submit="save" class="grid gap-4 md:max-w-2xl">
        <flux:input
            wire:model="username"
            :label="__('Username')"
            type="text"
            required
            autocomplete="username"
        />

        <flux:input
            wire:model="email"
            :label="__('Email')"
            type="email"
            required
            autocomplete="email"
        />

        <div class="grid gap-4 md:grid-cols-2">
            <flux:input
                wire:model="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                viewable
            />

            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm Password')"
                type="password"
                required
                autocomplete="new-password"
                viewable
            />
        </div>

        <div class="grid gap-2">
            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
            <div class="flex gap-3">
                <label class="inline-flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                    <input type="radio" value="active" wire:model="status" class="h-4 w-4 border-neutral-300 text-emerald-600 focus:ring-emerald-500">
                    {{ __('Active') }}
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                    <input type="radio" value="inactive" wire:model="status" class="h-4 w-4 border-neutral-300 text-amber-600 focus:ring-amber-500">
                    {{ __('Inactive') }}
                </label>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">
                {{ __('Save') }}
            </flux:button>
            <flux:button :href="route('users.index')" wire:navigate variant="ghost">
                {{ __('Cancel') }}
            </flux:button>
        </div>
    </form>
</div>

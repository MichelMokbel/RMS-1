<?php

use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Illuminate\Support\Str;
use Livewire\Volt\Layout;

new #[Layout('components.layouts.app')] class extends Component {
    public User $user;
    public string $username = '';
    public string $email = '';
    public string $status = 'active';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(User $user): void
    {
        $this->user = $user;
        $this->username = $user->username;
        $this->email = $user->email;
        $this->status = $user->status;
    }

    /**
     * Persist the user changes.
     */
    public function updateUser(): void
    {
        $this->validate([
            'username' => [
                'required',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique(User::class)->ignore($this->user->id),
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user->id),
            ],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $this->user->forceFill([
            'name' => Str::headline($this->username),
            'username' => Str::lower($this->username),
            'email' => Str::lower($this->email),
            'status' => $this->status,
        ]);

        if ($this->password !== '') {
            $this->user->password = $this->password;
        }

        $this->user->save();

        session()->flash('status', __('User updated successfully.'));

        $this->redirectRoute('users.index');
    }

    /**
     * Quickly toggle the status for this user.
     */
    public function toggleStatus(): void
    {
        $this->status = $this->status === 'active' ? 'inactive' : 'active';
        $this->user->status = $this->status;
        $this->user->save();
    }
}; ?>

<div class="w-full space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Edit User') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                {{ __('Update account details or change the password.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <flux:button :href="route('users.index')" wire:navigate variant="ghost">
                {{ __('Back to users') }}
            </flux:button>
            <flux:button variant="{{ $status === 'active' ? 'danger' : 'primary' }}" wire:click="toggleStatus">
                {{ $status === 'active' ? __('Deactivate') : __('Activate') }}
            </flux:button>
        </div>
    </div>

    <form wire:submit="updateUser" class="grid gap-4 md:max-w-2xl">
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
                :label="__('New Password')"
                type="password"
                autocomplete="new-password"
                viewable
            />

            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm Password')"
                type="password"
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
                {{ __('Save changes') }}
            </flux:button>
            <flux:button :href="route('users.index')" wire:navigate variant="ghost">
                {{ __('Cancel') }}
            </flux:button>
        </div>
    </form>
</div>

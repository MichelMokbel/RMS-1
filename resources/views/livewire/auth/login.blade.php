<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public string $username = '';
    public string $password = '';
    public bool $remember = false;

    /**
     * Attempt to authenticate the user using the username.
     */
    public function login(): void
    {
        $credentials = $this->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        $authenticated = Auth::attempt([
            'username' => Str::lower($credentials['username']),
            'password' => $credentials['password'],
            'status' => 'active',
        ], $this->remember);

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'username' => __('Invalid credentials or inactive account.'),
            ]);
        }

        session()->regenerate();

        $this->redirectIntended(route('dashboard', absolute: false));
    }
}; ?>

<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your username and password to continue')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form
            wire:submit="login"
            method="POST"
            action="{{ route('login') }}"
            class="flex flex-col gap-6"
        >
            @csrf
            <flux:input
                wire:model="username"
                name="username"
                :label="__('Username')"
                type="text"
                required
                autofocus
                autocomplete="username"
                placeholder="username"
            />

            <div class="relative">
                <flux:input
                    wire:model="password"
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                    {{ __('Forgot your password?') }}
                </flux:link>
            </div>

            <flux:checkbox wire:model="remember" name="remember" :label="__('Remember me')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts.auth>

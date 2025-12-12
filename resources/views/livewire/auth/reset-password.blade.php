<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Illuminate\Http\Request;

new class extends Component {
    public string $token = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(Request $request): void
    {
        $this->token = (string) $request->route('token');
        $this->email = (string) $request->query('email', '');
    }

    /**
     * Reset the user's password.
     */
    public function resetPassword(): void
    {
        $validated = $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:8'],
        ]);

        $status = Password::reset(
            [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $validated['password_confirmation'],
                'token' => $this->token,
            ],
            function ($user, $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        session()->flash('status', __($status));

        $this->redirectRoute('login');
    }
}; ?>

<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Reset password')" :description="__('Please enter your new password below')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form wire:submit="resetPassword" class="flex flex-col gap-6">
            <flux:input
                wire:model="email"
                name="email"
                :label="__('Email')"
                type="email"
                required
                autocomplete="email"
            />

            <flux:input
                wire:model="password"
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                viewable
            />

            <flux:input
                wire:model="password_confirmation"
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="reset-password-button">
                    {{ __('Reset password') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts.auth>

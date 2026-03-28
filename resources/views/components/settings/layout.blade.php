@props([
    'heading' => null,
    'subheading' => null,
    'contentClass' => 'mt-5 w-full max-w-lg',
])

<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        @php
            $user = auth()->user();
            $canManageFinanceSettings = $user && ($user->hasAnyRole(['admin', 'manager']) || $user->can('finance.access'));
            $canManagePosDevices = $canManageFinanceSettings || ($user && $user->can('settings.pos_terminals.manage'));
        @endphp

        <flux:navlist>
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('user-password.edit')" wire:navigate>{{ __('Password') }}</flux:navlist.item>
            @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                <flux:navlist.item :href="route('two-factor.show')" wire:navigate>{{ __('Two-Factor Auth') }}</flux:navlist.item>
            @endif
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            @if(auth()->check() && auth()->user()->hasRole('admin'))
                <flux:navlist.item :href="route('iam.users.index')" wire:navigate>{{ __('Identity & Access') }}</flux:navlist.item>
                <flux:navlist.item :href="route('customers.accounts.index')" wire:navigate>{{ __('Customer Accounts') }}</flux:navlist.item>
            @endif
            @if($canManageFinanceSettings)
                <flux:navlist.item :href="route('finance.settings')" wire:navigate>{{ __('Finance') }}</flux:navlist.item>
                <flux:navlist.item :href="route('settings.accounting')" wire:navigate>{{ __('Accounting Setup') }}</flux:navlist.item>
                <flux:navlist.item :href="route('settings.organization')" wire:navigate>{{ __('Organization') }}</flux:navlist.item>
                <flux:navlist.item :href="route('settings.payment-terms')" wire:navigate>{{ __('Payment Terms') }}</flux:navlist.item>
            @endif
            @if($canManagePosDevices)
                <flux:navlist.item :href="route('settings.pos-terminals')" wire:navigate>{{ __('POS Devices') }}</flux:navlist.item>
            @endif
            @if(auth()->check() && (auth()->user()->hasAnyRole(['admin','manager']) || auth()->user()->can('help.manage')))
                <flux:navlist.item :href="route('help.manage')" wire:navigate>{{ __('Manage Help') }}</flux:navlist.item>
            @endif
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading }}</flux:heading>
        <flux:subheading>{{ $subheading }}</flux:subheading>

        <div class="{{ $contentClass }}">
            {{ $slot }}
        </div>
    </div>
</div>

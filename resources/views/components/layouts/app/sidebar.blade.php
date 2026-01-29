<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between lg:hidden">
                <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />
            </div>

            <a href="{{ route('dashboard') }}" class="me-5 flex items-center space-x-2 rtl:space-x-reverse" wire:navigate>
                <x-app-logo />
            </a>

            @php
                $user = auth()->user();
                $isAdmin = $user?->hasRole('admin') ?? false;
                $isManager = $user?->hasAnyRole(['admin','manager']) ?? false;
                $isCashier = $user?->hasAnyRole(['admin','manager','cashier']) ?? false;
                $isStaff = $user?->hasAnyRole(['admin','manager','staff']) ?? false;

                $inPlatform = request()->routeIs('dashboard');
                $inAdmin = request()->routeIs('categories.*') || request()->routeIs('suppliers.*') || request()->routeIs('customers.*');
                $inOrders = request()->routeIs('orders.*') || request()->routeIs('meal-plan-requests.*') || request()->routeIs('subscriptions.*');
                $inCatalog = request()->routeIs('menu-items.*') || request()->routeIs('recipes.*');
                $inOps = request()->routeIs('inventory.*') || request()->routeIs('purchase-orders.*');
                $inDailyDish = request()->routeIs('daily-dish.*');
                $inFinance = request()->routeIs('payables.*')
                    || request()->routeIs('expenses.*')
                    || request()->routeIs('petty-cash.*')
                    || request()->routeIs('ledger.*');
            @endphp

            <flux:navlist variant="outline">
                <flux:navlist.group expandable :expanded="$inPlatform" :heading="__('Platform')">
                    <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:navlist.item>
                </flux:navlist.group>

                @if ($isAdmin)
                    <flux:navlist.group expandable :expanded="$inAdmin" :heading="__('Administration')">
                        <flux:navlist.item icon="folder" :href="route('categories.index')" :current="request()->routeIs('categories.*')" wire:navigate>
                            {{ __('Categories') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="truck" :href="route('suppliers.index')" :current="request()->routeIs('suppliers.*')" wire:navigate>
                            {{ __('Suppliers') }}
                        </flux:navlist.item>
                        @if ($isCashier)
                            <flux:navlist.item icon="users" :href="route('customers.index')" :current="request()->routeIs('customers.*')" wire:navigate>
                                {{ __('Customers') }}
                            </flux:navlist.item>
                        @endif
                    </flux:navlist.group>
                @endif

                @if ($isCashier)
                    <flux:navlist.group expandable :expanded="$inOrders" :heading="__('Orders')">
                        <flux:navlist.item icon="clipboard-document" :href="route('orders.index')" :current="request()->routeIs('orders.*')" wire:navigate>
                            {{ __('Orders') }}
                        </flux:navlist.item>
                        @if ($isManager)
                            <flux:navlist.item icon="clipboard-document" :href="route('meal-plan-requests.index')" :current="request()->routeIs('meal-plan-requests.*')" wire:navigate>
                                {{ __('Meal Plan Requests') }}
                            </flux:navlist.item>
                            <flux:navlist.item icon="ticket" :href="route('subscriptions.index')" :current="request()->routeIs('subscriptions.*')" wire:navigate>
                                {{ __('Subscriptions') }}
                            </flux:navlist.item>
                        @endif
                    </flux:navlist.group>

                    <flux:navlist.group expandable :expanded="$inCatalog" :heading="__('Catalog')">
                        <flux:navlist.item icon="list-bullet" :href="route('menu-items.index')" :current="request()->routeIs('menu-items.*')" wire:navigate>
                            {{ __('Menu Items') }}
                        </flux:navlist.item>
                        @if ($isManager)
                            <flux:navlist.item icon="building-storefront" :href="route('menu-items.availability')" :current="request()->routeIs('menu-items.availability')" wire:navigate>
                                {{ __('Menu Item Availability') }}
                            </flux:navlist.item>
                        @endif
                        @if ($isManager)
                            <flux:navlist.item icon="beaker" :href="route('recipes.index')" :current="request()->routeIs('recipes.*')" wire:navigate>
                                {{ __('Recipes') }}
                            </flux:navlist.item>
                            <flux:navlist.item icon="calendar-days" :href="route('daily-dish.menus.index')" :current="request()->routeIs('daily-dish.menus.*')" wire:navigate>
                                {{ __('Daily Dish') }}
                            </flux:navlist.item>
                        @endif
                    </flux:navlist.group>

                    <flux:navlist.group expandable :expanded="$inOps" :heading="__('Operations')">
                        <flux:navlist.item icon="archive-box" :href="route('inventory.index')" :current="request()->routeIs('inventory.*')" wire:navigate>
                            {{ __('Inventory') }}
                        </flux:navlist.item>
                        @if ($isManager)
                            <flux:navlist.item icon="arrows-right-left" :href="route('inventory.transfers')" :current="request()->routeIs('inventory.transfers')" wire:navigate>
                                {{ __('Transfers') }}
                            </flux:navlist.item>
                            <flux:navlist.item icon="clipboard-document-check" :href="route('purchase-orders.index')" :current="request()->routeIs('purchase-orders.*')" wire:navigate>
                                {{ __('Purchase Orders') }}
                            </flux:navlist.item>
                        @endif
                    </flux:navlist.group>
                @endif

                @if ($isManager || $isStaff)
                    <flux:navlist.group expandable :expanded="$inFinance" :heading="__('Finance')">
                        @if ($isManager)
                            <flux:navlist.item icon="banknotes" :href="route('payables.index')" :current="request()->routeIs('payables.*')" wire:navigate>
                                {{ __('Payables') }}
                            </flux:navlist.item>
                            <flux:navlist.item icon="credit-card" :href="route('expenses.index')" :current="request()->routeIs('expenses.*')" wire:navigate>
                                {{ __('Expenses') }}
                            </flux:navlist.item>
                            <flux:navlist.item icon="calculator" :href="route('ledger.batches.index')" :current="request()->routeIs('ledger.*')" wire:navigate>
                                {{ __('Ledger Batches') }}
                            </flux:navlist.item>
                        @endif
                        @if ($isStaff)
                            <flux:navlist.item icon="wallet" :href="route('petty-cash.index')" :current="request()->routeIs('petty-cash.*')" wire:navigate>
                                {{ __('Petty Cash') }}
                            </flux:navlist.item>
                        @endif
                    </flux:navlist.group>
                @endif
            </flux:navlist>

            <flux:spacer />

            <flux:navlist variant="outline">
                <flux:navlist.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                {{ __('Repository') }}
                </flux:navlist.item>

                <flux:navlist.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                {{ __('Documentation') }}
                </flux:navlist.item>
            </flux:navlist>

            <!-- Desktop User Menu -->
            <flux:dropdown class="hidden lg:block" position="bottom" align="start">
                <flux:profile
                    :name="auth()->user()->username"
                    :initials="auth()->user()->initials()"
                    icon:trailing="chevrons-up-down"
                    data-test="sidebar-menu-button"
                />

                <flux:menu class="w-[220px]">
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->username }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout-button">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->username }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout-button">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @livewireScripts
        @fluxScripts
    </body>
</html>

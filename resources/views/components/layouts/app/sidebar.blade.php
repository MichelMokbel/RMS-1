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

            @php
                $user = auth()->user();
                $isAdmin = $user?->hasRole('admin') ?? false;
                $isManager = $user?->hasAnyRole(['admin','manager']) ?? false;
                $isCashier = $user?->hasAnyRole(['admin','manager','cashier']) ?? false;
                $isStaff = $user?->hasAnyRole(['admin','manager','staff']) ?? false;
                $isPastryUser = $user?->hasRole('pastry-user') ?? false;
                $isKitchenUser = $user?->hasRole('kitchen') ?? false;
                $isProductionDisplayOnly = ! $isManager && ($isKitchenUser || $isPastryUser);
                $productionBranchId = ($user?->allowedBranchIds() ?? [])[0] ?? null;
                $productionHome = $isKitchenUser && $productionBranchId
                    ? route('kitchen.ops', [$productionBranchId, now()->toDateString()])
                    : ($isPastryUser && $productionBranchId
                        ? route('pastry-orders.display', [$productionBranchId, now()->toDateString()])
                        : route('dashboard'));
                $canAccessMarketing = $user?->can('marketing.access') ?? false;
                $canAccessHr = $isAdmin || ($user?->can('hr.access') ?? false);
                $canAccessQuotations = $user?->can('quotations.access') ?? false;
                $canManageQuotationTemplates = $user?->can('quotation-templates.manage') ?? false;
                $canManagePromotions = $isAdmin && ($user?->can('promotions.manage') ?? false);
                $canManageStorefront = $isAdmin && ($user?->can('storefront.manage') ?? false);
                $isAccounting = $user?->hasAnyRole(['admin', 'manager', 'accounting']) ?? false;

                $inSales = request()->routeIs('orders.*')
                    || request()->routeIs('pastry-orders.*')
                    || request()->routeIs('order-sheet.*')
                    || request()->routeIs('invoices.*')
                    || request()->routeIs('quotations.*')
                    || request()->routeIs('quotation-templates.*')
                    || request()->routeIs('receivables.payments.*')
                    || request()->routeIs('receivables.orders-to-invoice');
                $inPrograms = request()->routeIs('meal-plan-requests.*')
                    || request()->routeIs('subscriptions.*')
                    || request()->routeIs('company-food.*');
                $inCatalog = request()->routeIs('menu-items.*') || request()->routeIs('recipes.*') || request()->routeIs('daily-dish.*');
                $inSupplyChain = request()->routeIs('inventory.*')
                    || request()->routeIs('purchase-orders.*');
                $inFinance = request()->routeIs('payables.*')
                    || request()->routeIs('spend.*')
                    || request()->routeIs('expenses.*')
                    || request()->routeIs('petty-cash.*')
                    || request()->routeIs('ledger.*')
                    || request()->routeIs('accounting.*');
                $inReports = request()->routeIs('reports.*');
                $inAdministration = request()->routeIs('categories.*')
                    || request()->routeIs('customers.*')
                    || request()->routeIs('customers.accounts.*')
                    || request()->routeIs('membership-promotions.*')
                    || request()->routeIs('storefront.*')
                    || request()->routeIs('suppliers.*')
                    || request()->routeIs('iam.*')
                    || request()->routeIs('users.*');
                $inSupport = request()->routeIs('help.*');
                $inMarketing = request()->routeIs('marketing.*');
                $inHr = request()->routeIs('hr.*');
            @endphp

            <a href="{{ $isProductionDisplayOnly ? $productionHome : route('dashboard') }}" class="me-5 flex items-center space-x-2 rtl:space-x-reverse" wire:navigate>
                <x-app-logo />
            </a>

            <flux:navlist variant="outline">
                @unless ($isProductionDisplayOnly)
                    <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:navlist.item>
                @endunless

                @if ($isAdmin)
                    <flux:navlist.group expandable :expanded="$inAdministration" :heading="__('Administration')">
                        <flux:navlist.item icon="shield-check" :href="route('iam.users.index')" :current="request()->routeIs('iam.*') || request()->routeIs('users.*')" wire:navigate>
                            {{ __('Identity & Access') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="user-circle" :href="route('customers.accounts.index')" :current="request()->routeIs('customers.accounts.*')" wire:navigate>
                            {{ __('Customer Accounts') }}
                        </flux:navlist.item>
                        @if ($canManagePromotions)
                            <flux:navlist.item icon="gift" :href="route('membership-promotions.index')" :current="request()->routeIs('membership-promotions.*')" wire:navigate>
                                {{ __('Membership Promotions') }}
                            </flux:navlist.item>
                        @endif
                        @if ($canManageStorefront)
                            <flux:navlist.item icon="building-storefront" :href="route('storefront.index')" :current="request()->routeIs('storefront.*')" wire:navigate>
                                {{ __('Storefront') }}
                            </flux:navlist.item>
                        @endif
                        @if ($isCashier)
                            <flux:navlist.item icon="users" :href="route('customers.index')" :current="request()->routeIs('customers.*')" wire:navigate>
                                {{ __('Customers') }}
                            </flux:navlist.item>
                        @endif
                        <flux:navlist.item icon="truck" :href="route('suppliers.index')" :current="request()->routeIs('suppliers.*')" wire:navigate>
                            {{ __('Suppliers') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="folder" :href="route('categories.index')" :current="request()->routeIs('categories.*')" wire:navigate>
                            {{ __('Categories') }}
                        </flux:navlist.item>
                    </flux:navlist.group>
                @endif

                @if ($isProductionDisplayOnly && $productionBranchId)
                    <flux:navlist.group :heading="__('Production')">
                        @if ($isKitchenUser)
                            <flux:navlist.item icon="clipboard-document-list" :href="route('kitchen.ops', [$productionBranchId, now()->toDateString()])" :current="request()->routeIs('kitchen.ops')" wire:navigate>
                                {{ __('Kitchen preparation') }}
                            </flux:navlist.item>
                        @endif
                        @if ($isPastryUser)
                            <flux:navlist.item icon="cake" :href="route('pastry-orders.display', [$productionBranchId, now()->toDateString()])" :current="request()->routeIs('pastry-orders.display')" wire:navigate>
                                {{ __('Pastry display') }}
                            </flux:navlist.item>
                        @endif
                    </flux:navlist.group>
                @endif

                @if ($isCashier)
                    <flux:navlist.group expandable :expanded="$inSales" :heading="__('Sales')">
                        <flux:navlist.item icon="clipboard-document" :href="route('orders.index')" :current="request()->routeIs('orders.*')" wire:navigate>
                            {{ __('Orders') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="cake" :href="route('pastry-orders.index')" :current="request()->routeIs('pastry-orders.*')" wire:navigate>
                            {{ __('Pastry Orders') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="table-cells" :href="route('order-sheet.index')" :current="request()->routeIs('order-sheet.*')" wire:navigate>
                            {{ __('Order Sheet') }}
                        </flux:navlist.item>
                        @if ($user?->can('order-labels.print'))
                            <flux:navlist.item icon="printer" :href="route('order-labels.index')" :current="request()->routeIs('order-labels.*')" wire:navigate>
                                {{ __('Order Labels') }}
                            </flux:navlist.item>
                        @endif
                        @if ($canAccessQuotations)
                            <flux:navlist.item icon="document-plus" :href="route('quotations.index')" :current="request()->routeIs('quotations.*')" wire:navigate>
                                {{ __('Quotations') }}
                            </flux:navlist.item>
                        @endif
                        @if ($canManageQuotationTemplates)
                            <flux:navlist.item icon="swatch" :href="route('quotation-templates.index')" :current="request()->routeIs('quotation-templates.*')" wire:navigate>
                                {{ __('Quotation Templates') }}
                            </flux:navlist.item>
                        @endif
                        @if ($isManager)
                            <flux:navlist.item icon="clipboard-document-list" :href="route('receivables.orders-to-invoice')" :current="request()->routeIs('receivables.orders-to-invoice')" wire:navigate>
                                {{ __('Orders to Invoice') }}
                            </flux:navlist.item>
                            <flux:navlist.item icon="document-text" :href="route('invoices.index')" :current="request()->routeIs('invoices.*')" wire:navigate>
                                {{ __('Invoices (AR)') }}
                            </flux:navlist.item>
                            <flux:navlist.item icon="credit-card" :href="route('receivables.payments.index')" :current="request()->routeIs('receivables.payments.*')" wire:navigate>
                                {{ __('Customer Payments') }}
                            </flux:navlist.item>
                        @endif
                    </flux:navlist.group>

                    <flux:navlist.group expandable :expanded="$inSupplyChain" :heading="__('Supply Chain')">
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

                @if ($isManager || $isAccounting)
                    <flux:navlist.group expandable :expanded="$inFinance" :heading="__('Finance')">
                        <flux:navlist.item icon="building-library" :href="route('accounting.dashboard')" :current="request()->routeIs('accounting.*')" wire:navigate>
                            {{ __('Accounting Home') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="banknotes" :href="route('payables.index')" :current="request()->routeIs('payables.*')" wire:navigate>
                            {{ __('Accounts Payable') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="wallet" :href="route('petty-cash.index')" :current="request()->routeIs('petty-cash.*')" wire:navigate>
                            {{ __('Petty Cash') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="calculator" :href="route('ledger.batches.index')" :current="request()->routeIs('ledger.*')" wire:navigate>
                            {{ __('Ledger Batches') }}
                        </flux:navlist.item>
                    </flux:navlist.group>
                @endif

                @if ($isManager)
                    <flux:navlist.group expandable :expanded="$inPrograms" :heading="__('Programs')">
                        <flux:navlist.item icon="clipboard-document" :href="route('meal-plan-requests.index')" :current="request()->routeIs('meal-plan-requests.*')" wire:navigate>
                            {{ __('Meal Plan Requests') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="ticket" :href="route('subscriptions.index')" :current="request()->routeIs('subscriptions.*')" wire:navigate>
                            {{ __('Subscriptions') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="building-office-2" :href="route('company-food.projects.index')" :current="request()->routeIs('company-food.*')" wire:navigate>
                            {{ __('Company Food') }}
                        </flux:navlist.item>
                    </flux:navlist.group>
                @endif

                @if ($isAccounting && ! $isCashier)
                    <flux:navlist.group expandable :expanded="$inSales" :heading="__('Sales')">
                        @if ($canAccessQuotations)
                            <flux:navlist.item icon="document-plus" :href="route('quotations.index')" :current="request()->routeIs('quotations.*')" wire:navigate>
                                {{ __('Quotations') }}
                            </flux:navlist.item>
                        @endif
                        @if ($canManageQuotationTemplates)
                            <flux:navlist.item icon="swatch" :href="route('quotation-templates.index')" :current="request()->routeIs('quotation-templates.*')" wire:navigate>
                                {{ __('Quotation Templates') }}
                            </flux:navlist.item>
                        @endif
                        <flux:navlist.item icon="document-text" :href="route('invoices.index')" :current="request()->routeIs('invoices.*')" wire:navigate>
                            {{ __('Invoices (AR)') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="credit-card" :href="route('receivables.payments.index')" :current="request()->routeIs('receivables.payments.*')" wire:navigate>
                            {{ __('Customer Payments') }}
                        </flux:navlist.item>
                    </flux:navlist.group>
                @endif

                @if ($canAccessQuotations && ! $isCashier && ! $isAccounting)
                    <flux:navlist.group expandable :expanded="$inSales" :heading="__('Sales')">
                        <flux:navlist.item icon="document-plus" :href="route('quotations.index')" :current="request()->routeIs('quotations.*')" wire:navigate>
                            {{ __('Quotations') }}
                        </flux:navlist.item>
                        @if ($canManageQuotationTemplates)
                            <flux:navlist.item icon="swatch" :href="route('quotation-templates.index')" :current="request()->routeIs('quotation-templates.*')" wire:navigate>
                                {{ __('Quotation Templates') }}
                            </flux:navlist.item>
                        @endif
                    </flux:navlist.group>
                @endif

                @if ($isCashier)
                    <flux:navlist.group expandable :expanded="$inCatalog" :heading="__('Catalog & Production')">
                        <flux:navlist.item icon="list-bullet" :href="route('menu-items.index')" :current="request()->routeIs('menu-items.*')" wire:navigate>
                            {{ __('Menu Items') }}
                        </flux:navlist.item>
                        @if ($isManager)
                            <flux:navlist.item icon="building-storefront" :href="route('menu-items.availability')" :current="request()->routeIs('menu-items.availability')" wire:navigate>
                                {{ __('Menu Item Availability') }}
                            </flux:navlist.item>
                            <flux:navlist.item icon="tag" :href="route('menu-items.categorize')" :current="request()->routeIs('menu-items.categorize')" wire:navigate>
                                {{ __('Categorize Items') }}
                            </flux:navlist.item>
                            <flux:navlist.item icon="beaker" :href="route('recipes.index')" :current="request()->routeIs('recipes.*')" wire:navigate>
                                {{ __('Recipes') }}
                            </flux:navlist.item>
                            <flux:navlist.item icon="calendar-days" :href="route('daily-dish.menus.index')" :current="request()->routeIs('daily-dish.menus.*')" wire:navigate>
                                {{ __('Daily Dish') }}
                            </flux:navlist.item>
                        @endif
                    </flux:navlist.group>
                @endif

                @if($canAccessMarketing)
                    <flux:navlist.group expandable :expanded="$inMarketing" :heading="__('Marketing')">
                        <flux:navlist.item icon="chart-bar-square" :href="route('marketing.dashboard')" :current="request()->routeIs('marketing.dashboard')" wire:navigate>
                            {{ __('Dashboard') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="megaphone" :href="route('marketing.campaigns.index')" :current="request()->routeIs('marketing.campaigns.*')" wire:navigate>
                            {{ __('Campaigns') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="clock" :href="route('marketing.sync-logs.index')" :current="request()->routeIs('marketing.sync-logs.*')" wire:navigate>
                            {{ __('Sync Logs') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="photo" :href="route('marketing.assets.index')" :current="request()->routeIs('marketing.assets.*')" wire:navigate>
                            {{ __('Asset Library') }}
                        </flux:navlist.item>
                        <flux:navlist.item icon="document-text" :href="route('marketing.briefs.index')" :current="request()->routeIs('marketing.briefs.*')" wire:navigate>
                            {{ __('Briefs') }}
                        </flux:navlist.item>
                    </flux:navlist.group>
                @endif

                @if($canAccessHr)
                    <flux:navlist.group expandable :expanded="$inHr" :heading="__('Human Resources')">
                        <flux:navlist.item icon="user-group" :href="route('hr.dashboard')" :current="request()->routeIs('hr.dashboard')" wire:navigate>
                            {{ __('HR Dashboard') }}
                        </flux:navlist.item>
                        @if($isAdmin || $user?->can('hr.employees.view'))
                            <flux:navlist.item icon="identification" :href="route('hr.employees.index')" :current="request()->routeIs('hr.employees.*')" wire:navigate>{{ __('Employees') }}</flux:navlist.item>
                        @endif
                        @if($isAdmin || $user?->can('hr.leave.view'))
                            <flux:navlist.item icon="calendar-days" :href="route('hr.leave.index')" :current="request()->routeIs('hr.leave.*')" wire:navigate>{{ __('Leave') }}</flux:navlist.item>
                        @endif
                        @if($isAdmin || $user?->can('hr.payroll.view'))
                            <flux:navlist.item icon="banknotes" :href="route('hr.payroll.index')" :current="request()->routeIs('hr.payroll.*')" wire:navigate>{{ __('Payroll') }}</flux:navlist.item>
                        @endif
                    </flux:navlist.group>
                @endif

                @if ($isManager || $isStaff || $isAccounting)
                    <flux:navlist.group expandable :expanded="$inReports" :heading="__('Reports')">
                        <flux:navlist.item icon="chart-bar" :href="route('reports.index')" :current="request()->routeIs('reports.index')" wire:navigate>
                            {{ __('All Reports') }}
                        </flux:navlist.item>
                    </flux:navlist.group>
                @endif

                @unless ($isProductionDisplayOnly)
                    <flux:navlist.group :heading="__('Support')">
                        <flux:navlist.item icon="question-mark-circle" :href="route('help.index')" :current="$inSupport" wire:navigate>
                            {{ __('Help Center') }}
                        </flux:navlist.item>
                    </flux:navlist.group>
                @endunless

                @if (! $isProductionDisplayOnly && ! ($isAccounting && ! $isCashier))
                    <flux:navlist.group :heading="__('Tools')">
                        <flux:navlist.item
                            href="https://laylacardssystem.streamlit.app/"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {{ __('Cards Generator') }}
                        </flux:navlist.item>
                        <flux:navlist.item
                            href="{{ route('tools.pos-app') }}"
                        >
                            {{ __('Install POS App') }}
                        </flux:navlist.item>
                    </flux:navlist.group>
                @endif
            </flux:navlist>

            <flux:spacer />

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
        <x-help.bot />
        <x-toast />

        @livewireScripts
        @fluxScripts
    </body>
</html>

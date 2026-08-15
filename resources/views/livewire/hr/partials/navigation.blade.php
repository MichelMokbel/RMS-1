<div class="overflow-x-auto rounded-lg border border-neutral-200 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-900">
    <nav class="flex min-w-max gap-1" aria-label="{{ __('HR navigation') }}">
        @foreach ([
            ['hr.dashboard', 'Dashboard', 'hr.access'],
            ['hr.employees.index', 'Employees', 'hr.employees.view'],
            ['hr.leave.index', 'Leave', 'hr.leave.view'],
            ['hr.payroll.index', 'Payroll', 'hr.payroll.view'],
            ['hr.imports.index', 'Imports', 'hr.imports.manage'],
            ['hr.reports.index', 'Reports', 'hr.reports.view'],
            ['hr.settings.index', 'Settings', 'hr.settings.manage'],
            ['hr.audit.index', 'Audit', 'hr.audit.view'],
        ] as [$routeName, $label, $permission])
            @if (Route::has($routeName) && (auth()->user()?->hasRole('admin') || auth()->user()?->can($permission)))
                @php($active = $routeName === 'hr.dashboard' ? request()->routeIs($routeName) : request()->routeIs(Str::beforeLast($routeName, '.').'.*'))
                <a href="{{ route($routeName) }}" wire:navigate
                   @class([
                       'rounded-md px-3 py-2 text-sm font-medium',
                       'bg-neutral-900 text-white dark:bg-white dark:text-neutral-900' => $active,
                       'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800' => ! $active,
                   ])>{{ __($label) }}</a>
            @endif
        @endforeach
    </nav>
</div>

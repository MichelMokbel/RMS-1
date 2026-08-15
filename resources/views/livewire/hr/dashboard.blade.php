<?php

use App\Models\HrAlert;
use App\Models\HrEmployee;
use App\Models\HrLeaveRequest;
use App\Models\HrPayrollRun;
use App\Models\Branch;
use App\Services\HR\HrAlertService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public function mount(HrAlertService $alerts): void
    {
        abort_unless(auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.access'), 403);
        $companyIds = auth()->user()?->hasRole('admin')
            ? \App\Models\AccountingCompany::query()->pluck('id')
            : Branch::query()->whereIn('id', auth()->user()?->allowedBranchIds() ?: [-1])->pluck('company_id')->unique();
        foreach ($companyIds as $companyId) {
            $alerts->refreshCompany((int) $companyId);
        }
    }

    public function with(): array
    {
        $employees = $this->scope(HrEmployee::query());
        $employeeIds = (clone $employees)->pluck('id');
        $companyIds = auth()->user()?->hasRole('admin')
            ? \App\Models\AccountingCompany::query()->pluck('id')
            : Branch::query()->whereIn('id', auth()->user()?->allowedBranchIds() ?: [-1])->pluck('company_id')->unique();
        $payrollCompanyIds = auth()->user()?->hasRole('admin')
            ? $companyIds
            : Branch::query()->where('is_active', true)->get(['id', 'company_id'])->groupBy('company_id')
                ->filter(fn ($branches) => $branches->every(fn ($branch) => in_array((int) $branch->id, auth()->user()?->allowedBranchIds() ?: [], true)))
                ->keys();
        $alerts = HrAlert::query()->whereIn('company_id', $companyIds)->where(function ($query) use ($employeeIds): void {
            $query->whereNull('employee_id')->orWhereIn('employee_id', $employeeIds);
        })->where('status', 'open')->latest();

        return [
            'headcount' => (clone $employees)->where('employment_status', 'active')->count(),
            'onboarding' => (clone $employees)->where('employment_status', 'onboarding')->count(),
            'pendingLeave' => HrLeaveRequest::query()->whereIn('employee_id', $employeeIds)->where('status', 'pending_manager')->count(),
            'openPayroll' => auth()->user()?->hasRole('admin') || auth()->user()?->can('hr.payroll.view')
                ? HrPayrollRun::query()->whereIn('company_id', $payrollCompanyIds)->whereIn('status', ['draft', 'calculated', 'approved', 'posted'])->count()
                : null,
            'alerts' => $alerts->limit(12)->get(),
        ];
    }

    private function scope($query)
    {
        $user = auth()->user();
        if (! $user?->hasRole('admin')) {
            $ids = $user?->allowedBranchIds() ?? [];
            $query->whereIn('current_branch_id', $ids ?: [-1]);
            if ($user?->hasRole('manager') && ! $user->can('hr.employees.manage')) {
                $managerId = HrEmployee::query()->where('user_id', $user->id)->value('id');
                $query->where(fn ($q) => $q->where('manager_id', $managerId ?: -1)->orWhere('user_id', $user->id));
            }
        }

        return $query;
    }
}; ?>

<div class="app-page space-y-6">
    <div><h1 class="text-2xl font-semibold">{{ __('Human Resources') }}</h1><p class="text-sm text-neutral-500">{{ __('People, compliance, leave and payroll in one workspace.') }}</p></div>
    @include('livewire.hr.partials.navigation')
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach (array_filter([['Active employees', $headcount], ['Onboarding', $onboarding], ['Leave awaiting approval', $pendingLeave], $openPayroll === null ? null : ['Open payroll runs', $openPayroll]]) as [$label, $value])
            <div class="rounded-lg border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900"><p class="text-sm text-neutral-500">{{ __($label) }}</p><p class="mt-2 text-3xl font-semibold">{{ number_format($value) }}</p></div>
        @endforeach
    </div>
    <section class="rounded-lg border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
        <div class="border-b border-neutral-200 px-5 py-4 dark:border-neutral-700"><h2 class="font-semibold">{{ __('Compliance alerts') }}</h2><p class="text-sm text-neutral-500">{{ __('Missing and expiring employee documents. Alerts remain dashboard-only in Phase 1.') }}</p></div>
        <div class="divide-y divide-neutral-100 dark:divide-neutral-800">
            @forelse ($alerts as $alert)
                <div class="flex items-start justify-between gap-4 px-5 py-4"><div><p class="font-medium">{{ Str::headline($alert->type ?? 'Document alert') }}</p><p class="text-sm text-neutral-500">{{ $alert->message ?? __('Review the employee document record.') }}</p></div><span class="rounded-full bg-amber-100 px-2 py-1 text-xs text-amber-800">{{ Str::headline($alert->severity ?? 'warning') }}</span></div>
            @empty
                <p class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('No open compliance alerts.') }}</p>
            @endforelse
        </div>
    </section>
</div>

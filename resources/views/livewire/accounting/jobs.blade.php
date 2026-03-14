<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Job;
use App\Services\Accounting\JobCostingService;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $company_id = null;
    public ?int $branch_id = null;
    public string $name = '';
    public string $code = '';
    public string $status = 'active';
    public ?string $start_date = null;
    public ?string $end_date = null;
    public string $estimated_revenue = '0.00';
    public string $estimated_cost = '0.00';
    public ?string $notes = null;
    public ?int $selected_job_id = null;
    public ?string $transaction_date = null;
    public string $transaction_amount = '0.00';
    public string $transaction_type = 'cost';
    public ?string $transaction_memo = null;

    public function mount(): void
    {
        $this->company_id = AccountingCompany::query()->where('is_default', true)->value('id');
        $this->branch_id = Branch::query()->orderBy('id')->value('id');
        $this->code = 'JOB-'.now()->format('YmdHis');
        $this->transaction_date = now()->toDateString();
        $this->selected_job_id = Job::query()->when($this->company_id, fn ($query) => $query->where('company_id', $this->company_id))->value('id');
    }

    public function saveJob(JobCostingService $service): void
    {
        $job = $service->createJob($this->validate([
            'company_id' => ['nullable', 'integer', 'exists:accounting_companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:50'],
            'status' => ['required', 'in:active,on_hold,closed'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'estimated_revenue' => ['nullable', 'numeric', 'min:0'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]), (int) auth()->id());

        $this->selected_job_id = $job->id;
        $this->code = 'JOB-'.now()->format('YmdHis');
        $this->name = '';
        $this->estimated_revenue = '0.00';
        $this->estimated_cost = '0.00';
        $this->notes = null;

        session()->flash('status', __('Job created.'));
    }

    public function recordTransaction(JobCostingService $service): void
    {
        $data = $this->validate([
            'selected_job_id' => ['required', 'integer', 'exists:accounting_jobs,id'],
            'transaction_date' => ['required', 'date'],
            'transaction_amount' => ['required', 'numeric', 'min:0'],
            'transaction_type' => ['required', 'in:cost,revenue,adjustment'],
            'transaction_memo' => ['nullable', 'string', 'max:255'],
        ]);

        $job = Job::query()->findOrFail($data['selected_job_id']);
        $service->recordTransaction($job->load('phases', 'budgets'), [
            'transaction_date' => $data['transaction_date'],
            'amount' => $data['transaction_amount'],
            'transaction_type' => $data['transaction_type'],
            'memo' => $data['transaction_memo'],
        ], (int) auth()->id());

        $this->transaction_amount = '0.00';
        $this->transaction_memo = null;

        session()->flash('status', __('Job transaction recorded.'));
    }

    public function with(JobCostingService $service): array
    {
        $jobs = Schema::hasTable('accounting_jobs')
            ? Job::query()->withCount(['phases', 'transactions'])->latest('created_at')->limit(100)->get()
            : collect();

        return [
            'companies' => AccountingCompany::query()->orderBy('name')->get(),
            'branches' => Branch::query()->orderBy('name')->get(),
            'jobs' => $jobs,
            'profitability' => $jobs->mapWithKeys(fn (Job $job) => [$job->id => $service->profitability($job->load(['phases', 'budgets']))]),
        ];
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Jobs') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Maintain project jobs, record cost or revenue activity, and track profitability.') }}</p>
        </div>
        <flux:button :href="route('accounting.reports')" wire:navigate variant="ghost">{{ __('Open Reports') }}</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[360px,1fr]">
        <div class="space-y-6">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Create Job') }}</h2>
                <form wire:submit="saveJob" class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Company') }}</label>
                            <select wire:model="company_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                @foreach ($companies as $company)
                                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Branch') }}</label>
                            <select wire:model="branch_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <flux:input wire:model="code" :label="__('Job Code')" />
                    <flux:input wire:model="name" :label="__('Job Name')" />
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Status') }}</label>
                        <select wire:model="status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="active">{{ __('Active') }}</option>
                            <option value="on_hold">{{ __('On Hold') }}</option>
                            <option value="closed">{{ __('Closed') }}</option>
                        </select>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="start_date" type="date" :label="__('Start Date')" />
                        <flux:input wire:model="end_date" type="date" :label="__('End Date')" />
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="estimated_revenue" type="number" step="0.01" :label="__('Estimated Revenue')" />
                        <flux:input wire:model="estimated_cost" type="number" step="0.01" :label="__('Estimated Cost')" />
                    </div>
                    <flux:textarea wire:model="notes" :label="__('Notes')" rows="3" />
                    <div class="flex justify-end">
                        <flux:button type="submit">{{ __('Create Job') }}</flux:button>
                    </div>
                </form>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Record Job Transaction') }}</h2>
                <form wire:submit="recordTransaction" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Job') }}</label>
                        <select wire:model="selected_job_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            @foreach ($jobs as $job)
                                <option value="{{ $job->id }}">{{ $job->code }} · {{ $job->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="transaction_date" type="date" :label="__('Date')" />
                        <div>
                            <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Type') }}</label>
                            <select wire:model="transaction_type" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                <option value="cost">{{ __('Cost') }}</option>
                                <option value="revenue">{{ __('Revenue') }}</option>
                                <option value="adjustment">{{ __('Adjustment') }}</option>
                            </select>
                        </div>
                    </div>
                    <flux:input wire:model="transaction_amount" type="number" step="0.01" :label="__('Amount')" />
                    <flux:textarea wire:model="transaction_memo" :label="__('Memo')" rows="2" />
                    <div class="flex justify-end">
                        <flux:button type="submit">{{ __('Record Transaction') }}</flux:button>
                    </div>
                </form>
            </div>
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Code') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Name') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Cost') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Revenue') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Margin') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse($jobs as $job)
                            @php($summary = $profitability[$job->id] ?? null)
                            <tr>
                                <td class="px-3 py-2 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $job->code }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $job->name }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $job->status }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($summary['actual_cost'] ?? 0), 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($summary['actual_revenue'] ?? 0), 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($summary['actual_margin'] ?? 0), 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No jobs found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

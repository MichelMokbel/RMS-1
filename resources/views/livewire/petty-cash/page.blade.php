<?php

use App\Models\ExpenseCategory;
use App\Models\PettyCashExpense;
use App\Models\PettyCashIssue;
use App\Models\PettyCashReconciliation;
use App\Models\PettyCashWallet;
use App\Services\PettyCash\PettyCashExpenseWorkflowService;
use App\Services\PettyCash\PettyCashIssueService;
use App\Services\PettyCash\PettyCashIssueVoidService;
use App\Services\PettyCash\PettyCashReconciliationService;
use App\Services\PettyCash\PettyCashReconciliationVoidService;
use App\Services\PettyCash\PettyCashWalletService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $tab = 'wallets';

    public bool $showWalletModal = false;
    public bool $showIssueModal = false;
    public bool $showExpenseModal = false;
    public bool $showReconModal = false;

    public string $wallet_search = '';
    public string $wallet_active_filter = 'active';
    public array $walletForm = [
        'id' => null,
        'driver_id' => null,
        'driver_name' => '',
        'target_float' => 0,
        'balance' => 0,
        'active' => true,
    ];

    public ?int $issue_wallet_id = null;
    public string $issue_method = 'all';
    public ?string $issue_from = null;
    public ?string $issue_to = null;
    public array $issueForm = [
        'wallet_id' => null,
        'issue_date' => null,
        'amount' => 0,
        'method' => 'cash',
        'reference' => null,
    ];

    public ?int $expense_wallet_id = null;
    public string $expense_status = 'all';
    public ?int $expense_category_id = null;
    public ?string $expense_from = null;
    public ?string $expense_to = null;
    public array $expenseForm = [
        'wallet_id' => null,
        'category_id' => null,
        'expense_date' => null,
        'description' => '',
        'amount' => 0,
        'tax_amount' => 0,
    ];

    public ?int $recon_wallet_id = null;
    public array $reconForm = [
        'wallet_id' => null,
        'period_start' => null,
        'period_end' => null,
        'counted_balance' => 0,
        'note' => null,
    ];

    public function mount(): void
    {
        $today = now()->toDateString();
        $this->issueForm['issue_date'] = $today;
        $this->expenseForm['expense_date'] = $today;
        $this->reconForm['period_start'] = $today;
        $this->reconForm['period_end'] = $today;
    }

    public function with(): array
    {
        return [
            'wallets' => $this->wallets(),
            'issues' => $this->issues(),
            'expenses' => $this->expenses(),
            'reconciliations' => $this->reconciliations(),
            'categories' => Schema::hasTable('expense_categories') ? ExpenseCategory::orderBy('name')->get() : collect(),
        ];
    }

    private function wallets()
    {
        if (! Schema::hasTable('petty_cash_wallets')) {
            return collect();
        }

        return PettyCashWallet::query()
            ->when($this->wallet_active_filter === 'active', fn ($q) => $q->where('active', 1))
            ->when($this->wallet_active_filter === 'inactive', fn ($q) => $q->where('active', 0))
            ->when($this->wallet_search, fn ($q) => $q->where(function ($sub) {
                $sub->where('driver_name', 'like', '%'.$this->wallet_search.'%')
                    ->orWhere('driver_id', 'like', '%'.$this->wallet_search.'%');
            }))
            ->orderBy('driver_name')
            ->get();
    }

    private function issues()
    {
        if (! Schema::hasTable('petty_cash_issues')) {
            return collect();
        }

        return PettyCashIssue::with(['wallet', 'voidedBy'])
            ->when($this->issue_wallet_id, fn ($q) => $q->where('wallet_id', $this->issue_wallet_id))
            ->when($this->issue_method !== 'all', fn ($q) => $q->where('method', $this->issue_method))
            ->when($this->issue_from, fn ($q) => $q->whereDate('issue_date', '>=', $this->issue_from))
            ->when($this->issue_to, fn ($q) => $q->whereDate('issue_date', '<=', $this->issue_to))
            ->orderByDesc('issue_date')
            ->limit(50)
            ->get();
    }

    private function expenses()
    {
        if (! Schema::hasTable('petty_cash_expenses')) {
            return collect();
        }

        return PettyCashExpense::with(['wallet', 'category', 'submitter', 'approver'])
            ->when($this->expense_wallet_id, fn ($q) => $q->where('wallet_id', $this->expense_wallet_id))
            ->when($this->expense_category_id, fn ($q) => $q->where('category_id', $this->expense_category_id))
            ->when($this->expense_status !== 'all', fn ($q) => $q->where('status', $this->expense_status))
            ->when($this->expense_from, fn ($q) => $q->whereDate('expense_date', '>=', $this->expense_from))
            ->when($this->expense_to, fn ($q) => $q->whereDate('expense_date', '<=', $this->expense_to))
            ->orderByDesc('expense_date')
            ->limit(50)
            ->get();
    }

    private function reconciliations()
    {
        if (! Schema::hasTable('petty_cash_reconciliations')) {
            return collect();
        }

        return PettyCashReconciliation::with(['wallet', 'reconciler', 'voidedBy'])
            ->when($this->recon_wallet_id, fn ($q) => $q->where('wallet_id', $this->recon_wallet_id))
            ->orderByDesc('reconciled_at')
            ->limit(50)
            ->get();
    }

    public function openWalletModal(?int $id = null): void
    {
        if ($id) {
            $wallet = PettyCashWallet::findOrFail($id);
            $this->walletForm = [
                'id' => $wallet->id,
                'driver_id' => $wallet->driver_id,
                'driver_name' => $wallet->driver_name,
                'target_float' => (float) $wallet->target_float,
                'balance' => (float) $wallet->balance,
                'active' => (bool) $wallet->active,
            ];
        } else {
            $this->resetWalletForm();
        }
        $this->showWalletModal = true;
    }

    public function resetWalletForm(): void
    {
        $this->walletForm = [
            'id' => null,
            'driver_id' => null,
            'driver_name' => '',
            'target_float' => 0,
            'balance' => 0,
            'active' => true,
        ];
    }

    public function saveWallet(PettyCashWalletService $service): void
    {
        $data = $this->validate([
            'walletForm.driver_id' => ['nullable', 'integer'],
            'walletForm.driver_name' => ['nullable', 'string', 'max:150'],
            'walletForm.target_float' => ['required', 'numeric', 'min:0'],
            'walletForm.balance' => ['required', 'numeric'],
            'walletForm.active' => ['boolean'],
        ])['walletForm'];

        $id = $this->walletForm['id'] ?? null;

        if ($id) {
            $wallet = PettyCashWallet::findOrFail($id);
            $wallet->update($data);
        } else {
            $service->create($data, Auth::id());
        }

        $this->resetWalletForm();
        $this->showWalletModal = false;
        session()->flash('status', __('Wallet saved.'));
    }

    public function activateWallet(int $id, PettyCashWalletService $service): void
    {
        $wallet = PettyCashWallet::findOrFail($id);
        $service->activate($wallet);
        session()->flash('status', __('Wallet activated.'));
    }

    public function deactivateWallet(int $id, PettyCashWalletService $service): void
    {
        $wallet = PettyCashWallet::findOrFail($id);
        $service->deactivate($wallet);
        session()->flash('status', __('Wallet deactivated.'));
    }

    public function openIssueModal(): void
    {
        $this->showIssueModal = true;
    }

    public function createIssue(PettyCashIssueService $service): void
    {
        $data = $this->validate([
            'issueForm.wallet_id' => ['required', 'integer', 'exists:petty_cash_wallets,id'],
            'issueForm.issue_date' => ['required', 'date'],
            'issueForm.amount' => ['required', 'numeric', 'min:0.01'],
            'issueForm.method' => ['required', 'in:cash,card,bank_transfer,cheque,other'],
            'issueForm.reference' => ['nullable', 'string', 'max:100'],
        ])['issueForm'];

        $service->createIssue($data['wallet_id'], $data, Auth::id());

        $this->issueForm['amount'] = 0;
        $this->issueForm['reference'] = null;
        $this->showIssueModal = false;
        session()->flash('status', __('Issue recorded.'));
    }

    public function openExpenseModal(): void
    {
        $this->showExpenseModal = true;
    }

    public function saveExpense(string $mode = 'submitted'): void
    {
        $data = $this->validate([
            'expenseForm.wallet_id' => ['required', 'integer', 'exists:petty_cash_wallets,id'],
            'expenseForm.category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'expenseForm.expense_date' => ['required', 'date'],
            'expenseForm.description' => ['required', 'string', 'max:255'],
            'expenseForm.amount' => ['required', 'numeric', 'min:0'],
            'expenseForm.tax_amount' => ['required', 'numeric', 'min:0'],
        ])['expenseForm'];

        $status = $mode === 'draft' ? 'draft' : 'submitted';

        $wallet = PettyCashWallet::findOrFail($data['wallet_id']);
        if (! $wallet->isActive()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['wallet_id' => __('Wallet is inactive.')]);
        }

        PettyCashExpense::create([
            'wallet_id' => $data['wallet_id'],
            'category_id' => $data['category_id'],
            'expense_date' => $data['expense_date'],
            'description' => $data['description'],
            'amount' => $data['amount'],
            'tax_amount' => $data['tax_amount'],
            'total_amount' => round($data['amount'] + $data['tax_amount'], 2),
            'status' => $status,
            'submitted_by' => Auth::id(),
        ]);

        $this->expenseForm['description'] = '';
        $this->expenseForm['amount'] = 0;
        $this->expenseForm['tax_amount'] = 0;
        $this->showExpenseModal = false;
        session()->flash('status', __('Expense saved.'));
    }

    public function submitExpense(int $id, PettyCashExpenseWorkflowService $service): void
    {
        $expense = PettyCashExpense::findOrFail($id);
        $service->submit($expense, Auth::id());
        session()->flash('status', __('Expense submitted.'));
    }

    public function approveExpense(int $id, PettyCashExpenseWorkflowService $service): void
    {
        $this->authorizeManager();
        $expense = PettyCashExpense::findOrFail($id);

        try {
            $service->approve($expense, Auth::id());
            session()->flash('status', __('Expense approved.'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            $errors = collect($e->errors())->flatten();
            $message = $errors->first() ?? __('Unable to approve expense.');

            session()->flash('status', $message);
        }
    }

    public function rejectExpense(int $id, PettyCashExpenseWorkflowService $service): void
    {
        $this->authorizeManager();
        $expense = PettyCashExpense::findOrFail($id);
        $service->reject($expense, Auth::id());
        session()->flash('status', __('Expense rejected.'));
    }

    public function openReconModal(): void
    {
        $this->showReconModal = true;
    }

    public function reconcile(PettyCashReconciliationService $service): void
    {
        $data = $this->validate([
            'reconForm.wallet_id' => ['required', 'integer', 'exists:petty_cash_wallets,id'],
            'reconForm.period_start' => ['required', 'date'],
            'reconForm.period_end' => ['required', 'date'],
            'reconForm.counted_balance' => ['required', 'numeric'],
            'reconForm.note' => ['nullable', 'string'],
        ])['reconForm'];

        $service->reconcile($data['wallet_id'], $data, Auth::id());
        $this->showReconModal = false;
        session()->flash('status', __('Reconciliation saved.'));
    }

    public function voidIssue(int $id, PettyCashIssueVoidService $service): void
    {
        $this->authorizeManager();
        $issue = PettyCashIssue::findOrFail($id);
        $service->void($issue, Auth::id());
        session()->flash('status', __('Issue voided.'));
    }

    public function voidReconciliation(int $id, PettyCashReconciliationVoidService $service): void
    {
        $this->authorizeManager();
        $recon = PettyCashReconciliation::findOrFail($id);
        $service->void($recon, Auth::id());
        session()->flash('status', __('Reconciliation voided.'));
    }

    private function authorizeManager(): void
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (
            ! $user
            || (! $user->hasRole('admin') && ! $user->hasRole('manager'))
        ) {
            abort(403);
        }
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
            {{ __('Petty Cash') }}
        </h1>

        <div class="flex flex-wrap gap-2">
            <flux:button type="button" wire:click="openWalletModal">
                {{ __('New Wallet') }}
            </flux:button>
            <flux:button type="button" wire:click="openIssueModal">
                {{ __('New Issue') }}
            </flux:button>
            <flux:button type="button" wire:click="openExpenseModal">
                {{ __('New Expense') }}
            </flux:button>
            <flux:button type="button" wire:click="openReconModal">
                {{ __('New Reconciliation') }}
            </flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    {{-- Tabs --}}
    <div class="border-neutral-200 dark:border-neutral-800">
        <nav class="flex flex-wrap gap-2">
            @php
                $tabClasses = fn (string $name) => ($tab === $name)
                    ? 'inline-flex items-center rounded-full bg-neutral-900 px-3 py-1.5 text-xs font-semibold text-white dark:bg-neutral-50 dark:text-neutral-900'
                    : 'inline-flex items-center rounded-full bg-neutral-100 px-3 py-1.5 text-xs font-semibold text-neutral-700 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700';
            @endphp

            <button type="button" wire:click="$set('tab', 'wallets')" class="{{ $tabClasses('wallets') }}">
                {{ __('Wallets') }}
            </button>
            <button type="button" wire:click="$set('tab', 'issues')" class="{{ $tabClasses('issues') }}">
                {{ __('Issues') }}
            </button>
            <button type="button" wire:click="$set('tab', 'expenses')" class="{{ $tabClasses('expenses') }}">
                {{ __('Expenses') }}
            </button>
            <button type="button" wire:click="$set('tab', 'reconciliations')" class="{{ $tabClasses('reconciliations') }}">
                {{ __('Reconciliations') }}
            </button>
        </nav>
    </div>

    {{-- Wallets tab --}}
    @if ($tab === 'wallets')
        <div class="space-y-4">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[200px]">
                        <flux:input
                            wire:model.live.debounce.300ms="wallet_search"
                            placeholder="{{ __('Search driver or ID') }}"
                        />
                    </div>
                    <div class="flex items-center gap-2">
                        <label for="wallet_active_filter" class="text-sm font-medium text-neutral-700 dark:text-neutral-200">
                            {{ __('Status') }}
                        </label>
                        <select
                            id="wallet_active_filter"
                            wire:model.live="wallet_active_filter"
                            class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                        >
                            <option value="active">{{ __('Active') }}</option>
                            <option value="inactive">{{ __('Inactive') }}</option>
                            <option value="all">{{ __('All') }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Driver') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Driver ID') }}
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Target Float') }}
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Balance') }}
                            </th>
                            <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Status') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($wallets as $wallet)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                                    {{ $wallet->driver_name ?: '—' }}
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    {{ $wallet->driver_id ?: '—' }}
                                </td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                                    {{ number_format((float) $wallet->target_float, 2) }}
                                </td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                                    {{ number_format((float) $wallet->balance, 2) }}
                                </td>
                                <td class="px-3 py-2 text-sm text-center">
                                    <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold
                                        {{ $wallet->active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100' : 'bg-neutral-200 text-neutral-800 dark:bg-neutral-700 dark:text-neutral-100' }}">
                                        {{ $wallet->active ? __('Active') : __('Inactive') }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-sm text-right">
                                    <flux:button size="xs" type="button" wire:click="openWalletModal({{ $wallet->id }})">
                                        {{ __('Edit') }}
                                    </flux:button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                    {{ __('No wallets found.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Issues tab --}}
    @if ($tab === 'issues')
        <div class="space-y-4">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                {{-- Filters: keep these in a single row (wrapping on small screens) --}}
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[180px]">
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">
                            {{ __('Wallet') }}
                        </label>
                        <select
                            wire:model.live="issue_wallet_id"
                            class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                        >
                            <option value="">{{ __('All') }}</option>
                            @foreach ($wallets as $wallet)
                                <option value="{{ $wallet->id }}">
                                    {{ $wallet->driver_name ?: ($wallet->driver_id ?: __('Wallet #:id', ['id' => $wallet->id])) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex-1 min-w-[140px]">
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">
                            {{ __('Method') }}
                        </label>
                        <select
                            wire:model.live="issue_method"
                            class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                        >
                            <option value="all">{{ __('All') }}</option>
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="flex-1 min-w-[160px]">
                        <flux:input wire:model.live="issue_from" type="date" :label="__('Date From')" />
                    </div>

                    <div class="flex-1 min-w-[160px]">
                        <flux:input wire:model.live="issue_to" type="date" :label="__('Date To')" />
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Date') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Wallet') }}
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Amount') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Method') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Reference') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Status') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($issues as $issue)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                                    {{ $issue->issue_date?->format('Y-m-d') }}
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    {{ $issue->wallet?->driver_name ?: $issue->wallet?->driver_id ?: '—' }}
                                </td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                                    {{ number_format((float) $issue->amount, 2) }}
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    {{ $issue->method }}
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    {{ $issue->reference ?: '—' }}
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    @if($issue->voided_at)
                                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900 dark:text-amber-100">
                                            {{ __('Void') }}
                                        </span>
                                        <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                            {{ $issue->voided_at?->format('Y-m-d H:i') }}
                                            @if($issue->voidedBy)
                                                • {{ $issue->voidedBy->username ?? $issue->voidedBy->email }}
                                            @endif
                                        </div>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100">
                                            {{ __('Active') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    @if(! $issue->voided_at)
                                        <flux:button size="xs" variant="ghost" wire:click="voidIssue({{ $issue->id }})">
                                            {{ __('Void') }}
                                        </flux:button>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                    {{ __('No issues found for the selected filters.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Expenses tab --}}
    @if ($tab === 'expenses')
        <div class="space-y-4">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[180px]">
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">
                            {{ __('Wallet') }}
                        </label>
                        <select
                            wire:model.live="expense_wallet_id"
                            class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                        >
                            <option value="">{{ __('All') }}</option>
                            @foreach ($wallets as $wallet)
                                <option value="{{ $wallet->id }}">
                                    {{ $wallet->driver_name ?: ($wallet->driver_id ?: __('Wallet #:id', ['id' => $wallet->id])) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex-1 min-w-[180px]">
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">
                            {{ __('Category') }}
                        </label>
                        <select
                            wire:model.live="expense_category_id"
                            class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                        >
                            <option value="">{{ __('All') }}</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex-1 min-w-[150px]">
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">
                            {{ __('Status') }}
                        </label>
                        <select
                            wire:model.live="expense_status"
                            class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                        >
                            <option value="all">{{ __('All') }}</option>
                            <option value="draft">{{ __('Draft') }}</option>
                            <option value="submitted">{{ __('Submitted') }}</option>
                            <option value="approved">{{ __('Approved') }}</option>
                            <option value="rejected">{{ __('Rejected') }}</option>
                        </select>
                    </div>

                    <div class="flex-1 min-w-[150px]">
                        <flux:input wire:model.live="expense_from" type="date" :label="__('Date From')" />
                    </div>
                    <div class="flex-1 min-w-[150px]">
                        <flux:input wire:model.live="expense_to" type="date" :label="__('Date To')" />
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Date') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Wallet') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Category') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Description') }}
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Amount') }}
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Tax') }}
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Total') }}
                            </th>
                            <th class="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Status') }}
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($expenses as $exp)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                                    {{ $exp->expense_date?->format('Y-m-d') }}
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    {{ $exp->wallet?->driver_name ?: $exp->wallet?->driver_id ?: '—' }}
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    {{ $exp->category?->name ?: '—' }}
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    {{ $exp->description }}
                                </td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                                    {{ number_format((float) $exp->amount, 2) }}
                                </td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                                    {{ number_format((float) $exp->tax_amount, 2) }}
                                </td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                                    {{ number_format((float) $exp->total_amount, 2) }}
                                </td>
                                <td class="px-3 py-2 text-sm text-center">
                                    <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-50">
                                        {{ ucfirst($exp->status) }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-sm">
                                    @php
                                        $currentUser = auth()->user();
                                        $canManageExpenses = $currentUser && $currentUser->hasAnyRole(['admin', 'manager']);
                                    @endphp

                                    <div class="flex flex-wrap justify-end gap-2">
                                        {{-- Anyone allowed on the page can submit their own draft --}}
                                        @if ($exp->status === 'draft')
                                            <flux:button size="xs" type="button" wire:click="submitExpense({{ $exp->id }})">
                                                {{ __('Submit') }}
                                            </flux:button>
                                        @endif

                                        {{-- Only admins / managers can approve / reject --}}
                                        @if ($canManageExpenses && $exp->isApprovable())
                                            <flux:button size="xs" type="button" wire:click="approveExpense({{ $exp->id }})">
                                                {{ __('Approve') }}
                                            </flux:button>
                                        @endif

                                        @if ($canManageExpenses && in_array($exp->status, ['draft', 'submitted']))
                                            <flux:button size="xs" type="button" wire:click="rejectExpense({{ $exp->id }})" variant="ghost">
                                                {{ __('Reject') }}
                                            </flux:button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                    {{ __('No expenses found for the selected filters.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Reconciliations tab --}}
    @if ($tab === 'reconciliations')
        <div class="space-y-4">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[180px]">
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">
                            {{ __('Wallet') }}
                        </label>
                        <select
                            wire:model.live="recon_wallet_id"
                            class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                        >
                            <option value="">{{ __('All') }}</option>
                            @foreach ($wallets as $wallet)
                                <option value="{{ $wallet->id }}">
                                    {{ $wallet->driver_name ?: ($wallet->driver_id ?: __('Wallet #:id', ['id' => $wallet->id])) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Wallet') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Period') }}
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Counted Balance') }}
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('System Balance') }}
                            </th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Difference') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Reconciled At') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('By') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Status') }}
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">
                                {{ __('Actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($reconciliations as $rec)
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    {{ $rec->wallet?->driver_name ?: $rec->wallet?->driver_id ?: '—' }}
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    {{ $rec->period_start?->format('Y-m-d') }} – {{ $rec->period_end?->format('Y-m-d') }}
                                </td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                                    {{ number_format((float) $rec->counted_balance, 2) }}
                                </td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                                    {{ number_format((float) $rec->expected_balance, 2) }}
                                </td>
                                <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">
                                    {{ number_format((float) $rec->variance, 2) }}
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    {{ $rec->reconciled_at?->format('Y-m-d H:i') }}
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    {{ $rec->reconciler?->username ?? $rec->reconciler?->email ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    @if($rec->voided_at)
                                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900 dark:text-amber-100">
                                            {{ __('Void') }}
                                        </span>
                                        <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                            {{ $rec->voided_at?->format('Y-m-d H:i') }}
                                            @if($rec->voidedBy)
                                                • {{ $rec->voidedBy->username ?? $rec->voidedBy->email }}
                                            @endif
                                        </div>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100">
                                            {{ __('Active') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    @if(! $rec->voided_at)
                                        <flux:button size="xs" variant="ghost" wire:click="voidReconciliation({{ $rec->id }})">
                                            {{ __('Void') }}
                                        </flux:button>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                    {{ __('No reconciliations found.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Wallet modal --}}
    @if ($showWalletModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-lg rounded-lg border border-neutral-200 bg-white p-4 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                    {{ $walletForm['id'] ? __('Edit Wallet') : __('New Wallet') }}
                </h2>

                <div class="space-y-3">
                    <flux:input wire:model="walletForm.driver_id" type="number" :label="__('Driver ID')" />
                    <flux:input wire:model="walletForm.driver_name" :label="__('Driver Name')" />
                    <flux:input wire:model="walletForm.target_float" type="number" step="0.01" min="0" :label="__('Target Float')" />
                    <flux:input wire:model="walletForm.balance" type="number" step="0.01" :label="__('Balance')" />
                    <div class="pt-1">
                        <flux:checkbox wire:model="walletForm.active" :label="__('Active')" />
                    </div>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showWalletModal', false)" variant="ghost">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button type="button" wire:click="saveWallet" variant="primary">
                        {{ __('Save') }}
                    </flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- Issue modal --}}
    @if ($showIssueModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-md rounded-lg border border-neutral-200 bg-white p-4 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                    {{ __('Record Issue') }}
                </h2>

                <div class="space-y-3">
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">
                            {{ __('Wallet') }}
                        </label>
                        <select
                            wire:model="issueForm.wallet_id"
                            class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                        >
                            <option value="">{{ __('Select wallet') }}</option>
                            @foreach ($wallets as $wallet)
                                <option value="{{ $wallet->id }}">
                                    {{ $wallet->driver_name ?: ($wallet->driver_id ?: __('Wallet #:id', ['id' => $wallet->id])) }}
                                </option>
                            @endforeach
                        </select>
                        @error('issueForm.wallet_id')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <flux:input wire:model="issueForm.issue_date" type="date" :label="__('Issue Date')" />
                    <flux:input wire:model="issueForm.amount" type="number" step="0.01" min="0.01" :label="__('Amount')" />

                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">
                            {{ __('Method') }}
                        </label>
                        <select
                            wire:model="issueForm.method"
                            class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                        >
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <flux:input wire:model="issueForm.reference" :label="__('Reference')" />
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showIssueModal', false)" variant="ghost">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button type="button" wire:click="createIssue" variant="primary">
                        {{ __('Save') }}
                    </flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- Expense modal --}}
    @if ($showExpenseModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-lg rounded-lg border border-neutral-200 bg-white p-4 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                    {{ __('New Petty Cash Expense') }}
                </h2>

                <div class="space-y-3">
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">
                            {{ __('Wallet') }}
                        </label>
                        <select
                            wire:model="expenseForm.wallet_id"
                            class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                        >
                            <option value="">{{ __('Select wallet') }}</option>
                            @foreach ($wallets as $wallet)
                                <option value="{{ $wallet->id }}">
                                    {{ $wallet->driver_name ?: ($wallet->driver_id ?: __('Wallet #:id', ['id' => $wallet->id])) }}
                                </option>
                            @endforeach
                        </select>
                        @error('expenseForm.wallet_id')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">
                            {{ __('Category') }}
                        </label>
                        <select
                            wire:model="expenseForm.category_id"
                            class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                        >
                            <option value="">{{ __('Select category') }}</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                        @error('expenseForm.category_id')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <flux:input wire:model="expenseForm.expense_date" type="date" :label="__('Expense Date')" />
                    <flux:input wire:model="expenseForm.description" :label="__('Description')" />

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <flux:input wire:model="expenseForm.amount" type="number" step="0.01" min="0" :label="__('Amount')" />
                        <flux:input wire:model="expenseForm.tax_amount" type="number" step="0.01" min="0" :label="__('Tax')" />
                    </div>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showExpenseModal', false)" variant="ghost">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button type="button" wire:click="saveExpense('draft')">
                        {{ __('Save Draft') }}
                    </flux:button>
                    <flux:button type="button" wire:click="saveExpense('submitted')" variant="primary">
                        {{ __('Submit') }}
                    </flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- Reconciliation modal --}}
    @if ($showReconModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-lg rounded-lg border border-neutral-200 bg-white p-4 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                    {{ __('New Reconciliation') }}
                </h2>

                <div class="space-y-3">
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">
                            {{ __('Wallet') }}
                        </label>
                        <select
                            wire:model="reconForm.wallet_id"
                            class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                        >
                            <option value="">{{ __('Select wallet') }}</option>
                            @foreach ($wallets as $wallet)
                                <option value="{{ $wallet->id }}">
                                    {{ $wallet->driver_name ?: ($wallet->driver_id ?: __('Wallet #:id', ['id' => $wallet->id])) }}
                                </option>
                            @endforeach
                        </select>
                        @error('reconForm.wallet_id')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <flux:input wire:model="reconForm.period_start" type="date" :label="__('Period Start')" />
                        <flux:input wire:model="reconForm.period_end" type="date" :label="__('Period End')" />
                    </div>

                    <flux:input wire:model="reconForm.counted_balance" type="number" step="0.01" :label="__('Counted Balance')" />
                    <flux:textarea wire:model="reconForm.note" :label="__('Note')" rows="2" />
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <flux:button type="button" wire:click="$set('showReconModal', false)" variant="ghost">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button type="button" wire:click="reconcile" variant="primary">
                        {{ __('Save Reconciliation') }}
                    </flux:button>
                </div>
            </div>
        </div>
    @endif
</div>



<?php

use App\Models\BankAccount;
use App\Models\PettyCashReconciliation;
use App\Models\PettyCashWallet;
use App\Services\PettyCash\PettyCashIssueService;
use App\Services\PettyCash\PettyCashIssueVoidService;
use App\Services\PettyCash\PettyCashQueryService;
use App\Services\PettyCash\PettyCashReconciliationService;
use App\Services\PettyCash\PettyCashReconciliationVoidService;
use App\Services\PettyCash\PettyCashWalletService;
use App\Support\PettyCash\PettyCashIssueRules;
use App\Support\PettyCash\PettyCashReconciliationRules;
use App\Support\PettyCash\PettyCashWalletRules;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $tab = 'wallets';

    public ?int $editingWalletId = null;
    public array $walletForm = [
        'driver_id' => null,
        'driver_name' => '',
        'target_float' => 0,
        'active' => true,
    ];
    public array $issueForm = [
        'wallet_id' => null,
        'issue_date' => null,
        'amount' => null,
        'method' => 'cash',
        'bank_account_id' => null,
        'reference' => null,
    ];
    public array $reconForm = [
        'wallet_id' => null,
        'period_start' => null,
        'period_end' => null,
        'counted_balance' => null,
        'note' => null,
    ];

    public function mount(): void
    {
        $tab = (string) request()->query('tab', 'wallets');
        $this->tab = in_array($tab, ['wallets', 'funding', 'reconciliations'], true) ? $tab : 'wallets';
        $this->issueForm['issue_date'] = now()->toDateString();
        $this->reconForm['period_start'] = now()->startOfMonth()->toDateString();
        $this->reconForm['period_end'] = now()->toDateString();
    }

    public function with(): array
    {
        $query = app(PettyCashQueryService::class);

        return [
            'wallets' => $query->wallets('all'),
            'issues' => $query->issues(),
            'reconciliations' => $query->reconciliations(),
            'bankAccounts' => Schema::hasTable('bank_accounts')
                ? BankAccount::query()->where('is_active', true)->orderBy('name')->get()
                : collect(),
        ];
    }

    public function editWallet(int $walletId): void
    {
        abort_unless($this->canManageMutations(), 403);
        $wallet = PettyCashWallet::query()->findOrFail($walletId);
        $this->editingWalletId = $wallet->id;
        $this->walletForm = [
            'driver_id' => $wallet->driver_id,
            'driver_name' => $wallet->driver_name ?? '',
            'target_float' => (float) $wallet->target_float,
            'active' => (bool) $wallet->active,
        ];
        $this->tab = 'wallets';
        $this->resetErrorBag();
    }

    public function resetWalletForm(): void
    {
        $this->editingWalletId = null;
        $this->walletForm = [
            'driver_id' => null,
            'driver_name' => '',
            'target_float' => 0,
            'active' => true,
        ];
        $this->resetErrorBag();
    }

    public function saveWallet(PettyCashWalletService $service): void
    {
        abort_unless($this->canManageMutations(), 403);

        $data = $this->validate((new PettyCashWalletRules())->rules());

        if ($this->editingWalletId) {
            $wallet = PettyCashWallet::query()->findOrFail($this->editingWalletId);
            $wallet->fill([
                'driver_id' => $data['walletForm']['driver_id'],
                'driver_name' => $data['walletForm']['driver_name'],
                'target_float' => $data['walletForm']['target_float'],
            ]);
            $wallet->save();

            if ((bool) $data['walletForm']['active']) {
                $service->activate($wallet);
            } else {
                $service->deactivate($wallet);
            }

            session()->flash('status', __('Wallet updated.'));
        } else {
            $service->create([
                'driver_id' => $data['walletForm']['driver_id'],
                'driver_name' => $data['walletForm']['driver_name'],
                'target_float' => $data['walletForm']['target_float'],
                'balance' => 0,
                'active' => (bool) $data['walletForm']['active'],
            ], (int) auth()->id());

            session()->flash('status', __('Wallet created.'));
        }

        $this->resetWalletForm();
    }

    public function saveIssue(PettyCashIssueService $service): void
    {
        abort_unless($this->canManageMutations(), 403);

        $data = $this->validate((new PettyCashIssueRules())->rules());
        $payload = $data['issueForm'];

        if (($payload['method'] ?? null) !== 'bank_transfer') {
            $payload['bank_account_id'] = null;
        }

        $service->createIssue((int) $payload['wallet_id'], $payload, (int) auth()->id());

        $this->issueForm = [
            'wallet_id' => null,
            'issue_date' => now()->toDateString(),
            'amount' => null,
            'method' => 'cash',
            'bank_account_id' => null,
            'reference' => null,
        ];

        session()->flash('status', __('Funding issue created.'));
    }

    public function voidIssue(int $issueId, PettyCashIssueVoidService $service): void
    {
        abort_unless($this->canVoidMutations(), 403);
        $service->void(\App\Models\PettyCashIssue::query()->findOrFail($issueId), (int) auth()->id());
        session()->flash('status', __('Funding issue voided.'));
    }

    public function saveReconciliation(PettyCashReconciliationService $service): void
    {
        abort_unless($this->canManageMutations(), 403);
        $data = $this->validate((new PettyCashReconciliationRules())->rules());
        $service->reconcile((int) $data['reconForm']['wallet_id'], $data['reconForm'], (int) auth()->id());

        $this->reconForm = [
            'wallet_id' => null,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->toDateString(),
            'counted_balance' => null,
            'note' => null,
        ];

        session()->flash('status', __('Reconciliation recorded.'));
    }

    public function voidReconciliation(int $reconciliationId, PettyCashReconciliationVoidService $service): void
    {
        abort_unless($this->canVoidMutations(), 403);
        $service->void(PettyCashReconciliation::query()->findOrFail($reconciliationId), (int) auth()->id());
        session()->flash('status', __('Reconciliation voided.'));
    }

    public function updatedIssueFormMethod(): void
    {
        if (($this->issueForm['method'] ?? null) !== 'bank_transfer') {
            $this->issueForm['bank_account_id'] = null;
        }
    }

    public function canManageMutations(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasRole('admin') || $user?->hasRole('manager') || $user?->can('finance.access'));
    }

    public function canVoidMutations(): bool
    {
        return $this->canManageMutations();
    }
}; ?>

<div class="w-full max-w-7xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Petty Cash') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Manage wallets, funding issues, and reconciliations without editing balances directly.') }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('payables.index', ['tab' => 'expenses', 'expense_channel' => 'petty_cash'])" wire:navigate variant="ghost">
                {{ __('Open Petty Cash Expenses') }}
            </flux:button>
            <flux:button :href="route('payables.index')" wire:navigate variant="ghost">{{ __('Back to Payables') }}</flux:button>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="flex flex-wrap gap-3">
        @foreach (['wallets' => __('Wallets'), 'funding' => __('Funding'), 'reconciliations' => __('Reconciliations')] as $value => $label)
            <button
                type="button"
                wire:click="$set('tab', '{{ $value }}')"
                class="rounded-md px-3 py-2 text-sm font-semibold {{ $tab === $value ? 'bg-neutral-200 text-neutral-900 dark:bg-neutral-700 dark:text-neutral-50' : 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if($tab === 'wallets')
        <div class="grid gap-6 lg:grid-cols-[360px_minmax(0,1fr)]">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ $editingWalletId ? __('Edit Wallet') : __('New Wallet') }}</h2>
                    @if($editingWalletId)
                        <flux:button type="button" wire:click="resetWalletForm" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button>
                    @endif
                </div>
                <form wire:submit="saveWallet" class="space-y-4">
                    <flux:input wire:model="walletForm.driver_name" :label="__('Custodian Name')" />
                    <flux:input wire:model="walletForm.driver_id" type="number" :label="__('Custodian Code')" />
                    <flux:input wire:model="walletForm.target_float" type="number" step="0.01" min="0" :label="__('Target Float')" />
                    <label class="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                        <input type="checkbox" wire:model="walletForm.active" class="rounded border-neutral-300 text-primary-600 shadow-sm focus:ring-primary-500">
                        {{ __('Active') }}
                    </label>
                    <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Wallets are always created at zero balance in the web flow. Use Funding to add money.') }}</p>
                    <flux:button type="submit">{{ $editingWalletId ? __('Save Wallet') : __('Create Wallet') }}</flux:button>
                </form>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="app-table-shell">
                    <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Custodian') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Code') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Balance') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Target Float') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Created') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @forelse($wallets as $wallet)
                                <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                    <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $wallet->driver_name ?: '—' }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $wallet->driver_id ?: '—' }}</td>
                                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ number_format((float) $wallet->balance, 2) }}</td>
                                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ number_format((float) $wallet->target_float, 2) }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $wallet->active ? __('Active') : __('Inactive') }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $wallet->created_at?->format('Y-m-d') ?: '—' }}</td>
                                    <td class="px-3 py-2 text-right">
                                        @if($this->canManageMutations())
                                            <flux:button type="button" size="xs" wire:click="editWallet({{ $wallet->id }})">{{ __('Edit') }}</flux:button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No wallets found.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if($tab === 'funding')
        <div class="grid gap-6 lg:grid-cols-[420px_minmax(0,1fr)]">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ __('New Funding Issue') }}</h2>
                <form wire:submit="saveIssue" class="mt-4 space-y-4">
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Wallet') }}</label>
                        <select wire:model="issueForm.wallet_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('Select wallet…') }}</option>
                            @foreach($wallets->where('active', true) as $wallet)
                                <option value="{{ $wallet->id }}">{{ $wallet->driver_name ?: __('Wallet #:id', ['id' => $wallet->id]) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <flux:input wire:model="issueForm.issue_date" type="date" :label="__('Issue Date')" />
                    <flux:input wire:model="issueForm.amount" type="number" step="0.01" min="0.01" :label="__('Amount')" />
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Method') }}</label>
                        <select wire:model.live="issueForm.method" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="cash">{{ __('Cash') }}</option>
                            <option value="bank_transfer">{{ __('Bank Transfer') }}</option>
                            <option value="card">{{ __('Card') }}</option>
                            <option value="cheque">{{ __('Cheque') }}</option>
                            <option value="other">{{ __('Other') }}</option>
                        </select>
                    </div>
                    @if(($issueForm['method'] ?? null) === 'bank_transfer')
                        <div>
                            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Bank Account') }}</label>
                            <select wire:model="issueForm.bank_account_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                <option value="">{{ __('Select bank account…') }}</option>
                                @foreach($bankAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <flux:input wire:model="issueForm.reference" :label="__('Reference')" />
                    <flux:button type="submit" :disabled="!$this->canManageMutations()">{{ __('Create Funding Issue') }}</flux:button>
                </form>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="app-table-shell">
                    <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Wallet') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Method') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Bank Account') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Issued By') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @forelse($issues as $issue)
                                <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                    <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $issue->issue_date?->format('Y-m-d') }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $issue->wallet?->driver_name ?: '—' }}</td>
                                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ number_format((float) $issue->amount, 2) }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $issue->method }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $issue->bankAccount?->name ?: '—' }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $issue->reference ?: '—' }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $issue->issuer?->name ?: '—' }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $issue->voided_at ? __('Voided') : __('Posted') }}</td>
                                    <td class="px-3 py-2 text-right">
                                        @if(! $issue->voided_at && $this->canVoidMutations())
                                            <flux:button type="button" size="xs" variant="ghost" wire:click="voidIssue({{ $issue->id }})">{{ __('Void') }}</flux:button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No funding issues found.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if($tab === 'reconciliations')
        <div class="grid gap-6 lg:grid-cols-[420px_minmax(0,1fr)]">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ __('New Reconciliation') }}</h2>
                <form wire:submit="saveReconciliation" class="mt-4 space-y-4">
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Wallet') }}</label>
                        <select wire:model="reconForm.wallet_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('Select wallet…') }}</option>
                            @foreach($wallets as $wallet)
                                <option value="{{ $wallet->id }}">{{ $wallet->driver_name ?: __('Wallet #:id', ['id' => $wallet->id]) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <flux:input wire:model="reconForm.period_start" type="date" :label="__('Period Start')" />
                    <flux:input wire:model="reconForm.period_end" type="date" :label="__('Period End')" />
                    <flux:input wire:model="reconForm.counted_balance" type="number" step="0.01" :label="__('Counted Balance')" />
                    <flux:textarea wire:model="reconForm.note" :label="__('Note')" rows="3" />
                    <flux:button type="submit" :disabled="!$this->canManageMutations()">{{ __('Record Reconciliation') }}</flux:button>
                </form>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="app-table-shell">
                    <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Wallet') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Period') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Expected') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Counted') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Variance') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('By') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @forelse($reconciliations as $recon)
                                <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                    <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $recon->wallet?->driver_name ?: '—' }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $recon->period_start?->format('Y-m-d') }} - {{ $recon->period_end?->format('Y-m-d') }}</td>
                                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ number_format((float) $recon->expected_balance, 2) }}</td>
                                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ number_format((float) $recon->counted_balance, 2) }}</td>
                                    <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ number_format((float) $recon->variance, 2) }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $recon->reconciler?->name ?: '—' }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $recon->voided_at ? __('Voided') : __('Posted') }}</td>
                                    <td class="px-3 py-2 text-right">
                                        @if(! $recon->voided_at && $this->canVoidMutations())
                                            <flux:button type="button" size="xs" variant="ghost" wire:click="voidReconciliation({{ $recon->id }})">{{ __('Void') }}</flux:button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No reconciliations found.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>

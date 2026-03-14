<?php

use App\Models\BankAccount;
use App\Models\BankReconciliationRun;
use App\Models\BankStatementImport;
use App\Models\BankTransaction;
use App\Services\Banking\BankReconciliationService;
use App\Services\Banking\BankStatementImportService;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public ?int $bank_account_id = null;
    public $statement_file = null;
    public ?int $statement_import_id = null;
    public ?string $statement_date = null;
    public string $statement_ending_balance = '0.00';

    public function mount(): void
    {
        if (! Schema::hasTable('bank_accounts')) {
            return;
        }

        $firstAccount = BankAccount::query()->orderBy('name')->first();
        $this->bank_account_id = $firstAccount?->id;
        $this->statement_date = now()->toDateString();
        $this->statement_import_id = BankStatementImport::query()
            ->when($this->bank_account_id, fn ($query) => $query->where('bank_account_id', $this->bank_account_id))
            ->latest('processed_at')
            ->value('id');
    }

    public function updatedBankAccountId(): void
    {
        $this->statement_import_id = BankStatementImport::query()
            ->when($this->bank_account_id, fn ($query) => $query->where('bank_account_id', $this->bank_account_id))
            ->latest('processed_at')
            ->value('id');
    }

    public function importStatement(BankStatementImportService $service): void
    {
        $data = $this->validate([
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'statement_file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $account = BankAccount::query()->findOrFail($data['bank_account_id']);
        $result = $service->import($account, $this->statement_file, (int) auth()->id());

        $this->statement_file = null;
        $this->statement_import_id = $result['import']->id;

        session()->flash('status', __('Statement imported successfully.'));
    }

    public function reconcile(BankReconciliationService $service): void
    {
        $data = $this->validate([
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'statement_import_id' => ['nullable', 'integer', 'exists:bank_statement_imports,id'],
            'statement_date' => ['required', 'date'],
            'statement_ending_balance' => ['required', 'numeric'],
        ]);

        $account = BankAccount::query()->findOrFail($data['bank_account_id']);
        $service->reconcile($account, $data, (int) auth()->id());

        session()->flash('status', __('Reconciliation completed.'));
    }

    public function with(): array
    {
        $accounts = Schema::hasTable('bank_accounts')
            ? BankAccount::query()->orderBy('name')->get()
            : collect();

        $transactions = Schema::hasTable('bank_transactions')
            ? BankTransaction::query()
                ->with(['bankAccount', 'statementImport'])
                ->when($this->bank_account_id, fn ($query) => $query->where('bank_account_id', $this->bank_account_id))
                ->latest('transaction_date')
                ->limit(100)
                ->get()
            : collect();

        $imports = Schema::hasTable('bank_statement_imports')
            ? BankStatementImport::query()
                ->with('bankAccount')
                ->when($this->bank_account_id, fn ($query) => $query->where('bank_account_id', $this->bank_account_id))
                ->latest('processed_at')
                ->limit(12)
                ->get()
            : collect();

        $reconciliations = Schema::hasTable('bank_reconciliation_runs')
            ? BankReconciliationRun::query()
                ->with('bankAccount')
                ->when($this->bank_account_id, fn ($query) => $query->where('bank_account_id', $this->bank_account_id))
                ->latest('statement_date')
                ->limit(12)
                ->get()
            : collect();

        return compact('accounts', 'transactions', 'imports', 'reconciliations');
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Banking') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Import statements, reconcile book transactions, and monitor cleared cash activity.') }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('accounting.reports')" wire:navigate variant="ghost">{{ __('Reports') }}</flux:button>
            <flux:button :href="route('accounting.dashboard')" wire:navigate variant="ghost">{{ __('Back to Accounting') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[320px,1fr]">
        <div class="space-y-6">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Import Statement') }}</h2>
                <form wire:submit="importStatement" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Bank Account') }}</label>
                        <select wire:model.live="bank_account_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <flux:input type="file" wire:model="statement_file" accept=".csv,.txt" :label="__('Statement File')" />
                    <div class="flex justify-end">
                        <flux:button type="submit">{{ __('Import CSV') }}</flux:button>
                    </div>
                </form>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Run Reconciliation') }}</h2>
                <form wire:submit="reconcile" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Statement Import') }}</label>
                        <select wire:model="statement_import_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('Latest processed import') }}</option>
                            @foreach ($imports as $import)
                                <option value="{{ $import->id }}">{{ $import->file_name }} · {{ $import->processed_at?->format('Y-m-d H:i') }}</option>
                            @endforeach
                        </select>
                    </div>
                    <flux:input wire:model="statement_date" type="date" :label="__('Statement Date')" />
                    <flux:input wire:model="statement_ending_balance" type="number" step="0.01" :label="__('Statement Ending Balance')" />
                    <div class="flex justify-end">
                        <flux:button type="submit">{{ __('Reconcile') }}</flux:button>
                    </div>
                </form>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Recent Imports') }}</h2>
                    <span class="text-xs text-neutral-500">{{ $imports->count() }} {{ __('shown') }}</span>
                </div>
                <div class="app-table-shell">
                    <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('File') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Rows') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @forelse($imports as $import)
                                <tr>
                                    <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $import->file_name }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $import->status }}</td>
                                    <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ $import->imported_rows }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No statement imports found.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Reconciliation Runs') }}</h2>
                    <span class="text-xs text-neutral-500">{{ $reconciliations->count() }} {{ __('shown') }}</span>
                </div>
                <div class="app-table-shell">
                    <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Variance') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @forelse($reconciliations as $run)
                                <tr>
                                    <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $run->statement_date?->format('Y-m-d') }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $run->status }}</td>
                                    <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $run->variance_amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No reconciliation runs found.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Bank Transactions') }}</h2>
                <div class="app-table-shell">
                    <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @forelse($transactions as $transaction)
                                <tr>
                                    <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $transaction->transaction_date?->format('Y-m-d') }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $transaction->reference ?? $transaction->memo ?? $transaction->transaction_type }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $transaction->status }}</td>
                                    <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">
                                        {{ $transaction->direction === 'outflow' ? '-' : '' }}{{ number_format((float) $transaction->amount, 2) }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No transactions found.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

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
    public ?int $selected_reconciliation_id = null;
    public ?int $selected_statement_transaction_id = null;
    public ?int $selected_book_transaction_id = null;

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
        $this->selected_reconciliation_id = BankReconciliationRun::query()
            ->when($this->bank_account_id, fn ($query) => $query->where('bank_account_id', $this->bank_account_id))
            ->latest('statement_date')
            ->value('id');
    }

    public function updatedBankAccountId(): void
    {
        $this->statement_import_id = BankStatementImport::query()
            ->when($this->bank_account_id, fn ($query) => $query->where('bank_account_id', $this->bank_account_id))
            ->latest('processed_at')
            ->value('id');
        $this->selected_reconciliation_id = BankReconciliationRun::query()
            ->when($this->bank_account_id, fn ($query) => $query->where('bank_account_id', $this->bank_account_id))
            ->latest('statement_date')
            ->value('id');
        $this->selected_statement_transaction_id = null;
        $this->selected_book_transaction_id = null;
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
        $result = $service->reconcile($account, $data, (int) auth()->id());
        $this->selected_reconciliation_id = (int) $result['run']->id;
        $this->selected_statement_transaction_id = null;
        $this->selected_book_transaction_id = null;

        session()->flash('status', $result['run']->status === 'completed'
            ? __('Reconciliation auto-matched and closed.')
            : __('Reconciliation created for review. Resolve exceptions before closing.'));
    }

    public function matchSelected(BankReconciliationService $service): void
    {
        $data = $this->validate([
            'selected_reconciliation_id' => ['required', 'integer', 'exists:bank_reconciliation_runs,id'],
            'selected_statement_transaction_id' => ['required', 'integer', 'exists:bank_transactions,id'],
            'selected_book_transaction_id' => ['required', 'integer', 'exists:bank_transactions,id'],
        ]);

        $service->match(
            BankReconciliationRun::query()->findOrFail($data['selected_reconciliation_id']),
            (int) $data['selected_statement_transaction_id'],
            (int) $data['selected_book_transaction_id'],
            (int) auth()->id()
        );

        $this->selected_statement_transaction_id = null;
        $this->selected_book_transaction_id = null;
        session()->flash('status', __('Transactions matched.'));
    }

    public function markException(int $transactionId, BankReconciliationService $service): void
    {
        abort_if(! $this->selected_reconciliation_id, 404);

        $service->markException(
            BankReconciliationRun::query()->findOrFail($this->selected_reconciliation_id),
            $transactionId,
            (int) auth()->id()
        );

        if ($this->selected_statement_transaction_id === $transactionId) {
            $this->selected_statement_transaction_id = null;
        }

        session()->flash('status', __('Statement line marked as exception.'));
    }

    public function unmatchTransaction(int $transactionId, BankReconciliationService $service): void
    {
        abort_if(! $this->selected_reconciliation_id, 404);

        $service->unmatch(
            BankReconciliationRun::query()->findOrFail($this->selected_reconciliation_id),
            $transactionId,
            (int) auth()->id()
        );

        session()->flash('status', __('Matched transactions returned to review.'));
    }

    public function closeRun(BankReconciliationService $service): void
    {
        abort_if(! $this->selected_reconciliation_id, 404);

        $service->close(BankReconciliationRun::query()->findOrFail($this->selected_reconciliation_id), (int) auth()->id());
        session()->flash('status', __('Reconciliation closed.'));
    }

    public function reopenRun(BankReconciliationService $service): void
    {
        abort_if(! $this->selected_reconciliation_id, 404);

        $service->reopen(BankReconciliationRun::query()->findOrFail($this->selected_reconciliation_id), (int) auth()->id());
        session()->flash('status', __('Reconciliation reopened for review.'));
    }

    public function with(): array
    {
        $accounts = Schema::hasTable('bank_accounts')
            ? BankAccount::query()->orderBy('name')->get()
            : collect();

        $transactions = Schema::hasTable('bank_transactions')
            ? BankTransaction::query()
                ->with(['bankAccount', 'statementImport', 'matchedTransaction'])
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

        $currentRun = $this->selected_reconciliation_id
            ? BankReconciliationRun::query()->with(['bankAccount', 'statementImport'])->find($this->selected_reconciliation_id)
            : null;

        $statementLines = collect();
        $bookLines = collect();
        $summary = [
            'matched_count' => 0,
            'exception_count' => 0,
            'unmatched_count' => 0,
            'outstanding_count' => 0,
            'variance_amount' => 0,
        ];

        if ($currentRun) {
            $statementLines = BankTransaction::query()
                ->with('matchedTransaction')
                ->where('bank_account_id', $currentRun->bank_account_id)
                ->whereNotNull('statement_import_id')
                ->when($currentRun->statement_import_id, fn ($query) => $query->where('statement_import_id', $currentRun->statement_import_id))
                ->whereDate('transaction_date', '<=', $currentRun->statement_date?->toDateString() ?? now()->toDateString())
                ->orderBy('transaction_date')
                ->get();

            $bookLines = BankTransaction::query()
                ->with('matchedTransaction')
                ->where('bank_account_id', $currentRun->bank_account_id)
                ->whereNull('statement_import_id')
                ->where('status', '!=', 'void')
                ->whereDate('transaction_date', '<=', $currentRun->statement_date?->toDateString() ?? now()->toDateString())
                ->orderBy('transaction_date')
                ->get();

            $summary = [
                'matched_count' => $statementLines->where('reconciliation_run_id', $currentRun->id)->where('status', 'matched')->count(),
                'exception_count' => $statementLines->where('reconciliation_run_id', $currentRun->id)->where('status', 'exception')->count(),
                'unmatched_count' => $statementLines->filter(fn ($line) => in_array($line->status, ['open'], true)
                    || ((int) ($line->reconciliation_run_id ?? 0) !== (int) $currentRun->id && ! $line->is_cleared))->count(),
                'outstanding_count' => $bookLines->where('status', 'open')->where('is_cleared', false)->count(),
                'variance_amount' => (float) $currentRun->variance_amount,
            ];
        }

        return compact('accounts', 'transactions', 'imports', 'reconciliations', 'currentRun', 'statementLines', 'bookLines', 'summary');
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Banking') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Import statements, review reconciliation runs, manually match exceptions, and explicitly close the run when it is ready.') }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('reports.accounting-bank-reconciliation')" wire:navigate variant="ghost">{{ __('Reports') }}</flux:button>
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
                        <flux:button type="submit">{{ __('Auto Match') }}</flux:button>
                    </div>
                </form>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Reconciliation Run') }}</h2>
                <div class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Run') }}</label>
                        <select wire:model.live="selected_reconciliation_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('Select run') }}</option>
                            @foreach ($reconciliations as $run)
                                <option value="{{ $run->id }}">{{ $run->statement_date?->format('Y-m-d') }} · {{ $run->status }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if($currentRun)
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <p class="text-neutral-500">{{ __('Status') }}</p>
                                <p class="font-semibold text-neutral-900 dark:text-neutral-100">{{ \Illuminate\Support\Str::headline($currentRun->status) }}</p>
                            </div>
                            <div>
                                <p class="text-neutral-500">{{ __('Variance') }}</p>
                                <p class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) $summary['variance_amount'], 2) }}</p>
                            </div>
                            <div>
                                <p class="text-neutral-500">{{ __('Matched') }}</p>
                                <p class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $summary['matched_count'] }}</p>
                            </div>
                            <div>
                                <p class="text-neutral-500">{{ __('Exceptions') }}</p>
                                <p class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $summary['exception_count'] }}</p>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <flux:button type="button" wire:click="closeRun" size="sm">{{ __('Close Run') }}</flux:button>
                            <flux:button type="button" wire:click="reopenRun" variant="ghost" size="sm">{{ __('Reopen') }}</flux:button>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="space-y-6">
            @if($currentRun)
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Manual Match Workbench') }}</h2>
                            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Select one statement line and one book transaction to create a manual match.') }}</p>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Statement Line') }}</label>
                            <select wire:model="selected_statement_transaction_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                <option value="">{{ __('Select statement line') }}</option>
                                @foreach ($statementLines->filter(fn ($line) => $line->status !== 'matched') as $line)
                                    <option value="{{ $line->id }}">{{ $line->transaction_date?->format('Y-m-d') }} · {{ number_format((float) $line->amount, 2) }} · {{ $line->reference ?: __('No ref') }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Book Transaction') }}</label>
                            <select wire:model="selected_book_transaction_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                <option value="">{{ __('Select book transaction') }}</option>
                                @foreach ($bookLines->filter(fn ($line) => $line->status === 'open' || (int) ($line->reconciliation_run_id ?? 0) === (int) $currentRun->id) as $line)
                                    <option value="{{ $line->id }}">{{ $line->transaction_date?->format('Y-m-d') }} · {{ number_format((float) $line->amount, 2) }} · {{ $line->reference ?: __('No ref') }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="mt-4 flex justify-end">
                        <flux:button type="button" wire:click="matchSelected">{{ __('Match Selected Lines') }}</flux:button>
                    </div>
                </div>

                <div class="grid gap-6 xl:grid-cols-2">
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="mb-3 flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Statement Lines') }}</h2>
                            <span class="text-xs text-neutral-500">{{ $statementLines->count() }} {{ __('shown') }}</span>
                        </div>
                        <div class="app-table-shell">
                            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                    @foreach($statementLines as $line)
                                        <tr>
                                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $line->transaction_date?->format('Y-m-d') }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $line->reference ?: '—' }}</td>
                                            <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $line->amount, 2) }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $line->status }}</td>
                                            <td class="px-3 py-2 text-right text-sm">
                                                <div class="flex justify-end gap-2">
                                                    @if($line->status === 'matched')
                                                        <flux:button type="button" wire:click="unmatchTransaction({{ $line->id }})" variant="ghost" size="sm">{{ __('Unmatch') }}</flux:button>
                                                    @else
                                                        <flux:button type="button" wire:click="markException({{ $line->id }})" variant="ghost" size="sm">{{ __('Exception') }}</flux:button>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="mb-3 flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Book Transactions') }}</h2>
                            <span class="text-xs text-neutral-500">{{ $bookLines->count() }} {{ __('shown') }}</span>
                        </div>
                        <div class="app-table-shell">
                            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                    @foreach($bookLines as $line)
                                        <tr>
                                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $line->transaction_date?->format('Y-m-d') }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $line->reference ?: '—' }}</td>
                                            <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $line->amount, 2) }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $line->status }}</td>
                                            <td class="px-3 py-2 text-right text-sm">
                                                @if($line->status === 'reconciled' && (int) ($line->reconciliation_run_id ?? 0) === (int) $currentRun->id)
                                                    <flux:button type="button" wire:click="unmatchTransaction({{ $line->id }})" variant="ghost" size="sm">{{ __('Unmatch') }}</flux:button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

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
        </div>
    </div>
</div>

<?php

use App\Models\AccountingAccountMapping;
use App\Models\AccountingCompany;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\LedgerAccountMappingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public string $tab = 'chart';
    public ?int $selected_company_id = null;

    public ?int $account_editing_id = null;
    public ?int $account_company_id = null;
    public ?int $parent_account_id = null;
    public string $account_code = '';
    public string $account_name = '';
    public string $account_type = 'asset';
    public ?string $account_class = null;
    public ?string $account_detail_type = null;
    public bool $account_is_active = true;
    public bool $account_allow_direct_posting = true;

    public ?int $bank_editing_id = null;
    public ?int $bank_company_id = null;
    public ?int $bank_branch_id = null;
    public ?int $bank_ledger_account_id = null;
    public string $bank_name = '';
    public string $bank_code = '';
    public string $bank_type = 'checking';
    public ?string $bank_institution_name = null;
    public ?string $bank_last4 = null;
    public string $bank_currency = 'QAR';
    public bool $bank_is_default = false;
    public bool $bank_is_active = true;
    public string $bank_opening_balance = '0.00';
    public ?string $bank_opening_balance_date = null;

    public array $mapping_values = [];

    public function mount(LedgerAccountMappingService $mappingService): void
    {
        $this->authorizeFinanceSettings();
        $requestedTab = (string) request()->query('tab', 'chart');
        $this->tab = in_array($requestedTab, array_keys($this->tabs()), true) ? $requestedTab : 'chart';
        $this->selected_company_id = AccountingCompany::query()->where('is_default', true)->value('id');
        $mappingService->bootstrapCompanyMappings($this->selected_company_id);
        $this->hydrateMappingValues($mappingService);
        $this->resetAccountForm();
        $this->resetBankForm();
    }

    public function with(LedgerAccountMappingService $mappingService): array
    {
        $this->authorizeFinanceSettings();

        if ($this->selected_company_id) {
            $mappingService->bootstrapCompanyMappings($this->selected_company_id);
        }

        $companies = AccountingCompany::query()->orderByDesc('is_default')->orderBy('name')->get();
        $accounts = LedgerAccount::query()
            ->with(['company', 'parent'])
            ->when($this->selected_company_id, function ($query) {
                $query->where(function ($builder) {
                    $builder->whereNull('company_id')->orWhere('company_id', $this->selected_company_id);
                });
            })
            ->orderBy('code')
            ->get();

        $bankAccounts = BankAccount::query()
            ->with(['company', 'ledgerAccount'])
            ->when($this->selected_company_id, fn ($query) => $query->where('company_id', $this->selected_company_id))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return [
            'tabs' => $this->tabs(),
            'companies' => $companies,
            'accounts' => $accounts,
            'parentAccountOptions' => $accounts->filter(fn (LedgerAccount $account) => $this->account_editing_id !== (int) $account->id),
            'companyAccounts' => $accounts->filter(fn (LedgerAccount $account) => $this->selected_company_id ? ((int) ($account->company_id ?? $this->selected_company_id) === (int) $this->selected_company_id) : true),
            'branches' => Branch::query()
                ->when($this->selected_company_id, fn ($query) => $query->where('company_id', $this->selected_company_id))
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'bankAccounts' => $bankAccounts,
            'mappingDefinitions' => $mappingService->definitions(),
            'mappingRows' => $mappingService->mappingsForCompany($this->selected_company_id)->keyBy('mapping_key'),
        ];
    }

    public function updatedSelectedCompanyId(): void
    {
        /** @var LedgerAccountMappingService $mappingService */
        $mappingService = app(LedgerAccountMappingService::class);
        $mappingService->bootstrapCompanyMappings($this->selected_company_id);
        $this->hydrateMappingValues($mappingService);
        $this->resetAccountForm();
        $this->resetBankForm();
    }

    public function saveAccount(AccountingAuditLogService $auditLog): void
    {
        $this->authorizeFinanceSettings();

        $data = $this->validate([
            'account_company_id' => ['nullable', 'integer', 'exists:accounting_companies,id'],
            'parent_account_id' => ['nullable', 'integer', 'exists:ledger_accounts,id'],
            'account_code' => ['required', 'string', 'max:50', Rule::unique('ledger_accounts', 'code')->ignore($this->account_editing_id)],
            'account_name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', 'in:asset,liability,equity,income,expense'],
            'account_class' => ['nullable', 'string', 'max:30'],
            'account_detail_type' => ['nullable', 'string', 'max:50'],
            'account_is_active' => ['required', 'boolean'],
            'account_allow_direct_posting' => ['required', 'boolean'],
        ]);

        $attributes = [
            'company_id' => $data['account_company_id'] ?: $this->selected_company_id,
            'parent_account_id' => $data['parent_account_id'] ?: null,
            'code' => strtoupper(trim($data['account_code'])),
            'name' => trim($data['account_name']),
            'type' => $data['account_type'],
            'account_class' => $data['account_class'] ? trim($data['account_class']) : $data['account_type'],
            'detail_type' => $data['account_detail_type'] ? trim($data['account_detail_type']) : null,
            'is_active' => (bool) $data['account_is_active'],
            'allow_direct_posting' => (bool) $data['account_allow_direct_posting'],
        ];

        $account = $this->account_editing_id
            ? tap(LedgerAccount::query()->findOrFail($this->account_editing_id))->update($attributes)
            : LedgerAccount::query()->create($attributes);

        $account = $account->fresh();

        $auditLog->log(
            $this->account_editing_id ? 'settings.ledger_account.updated' : 'settings.ledger_account.created',
            (int) Auth::id(),
            $account,
            ['code' => $account->code, 'type' => $account->type],
            (int) ($account->company_id ?? $this->selected_company_id)
        );

        session()->flash('status', $this->account_editing_id ? __('Ledger account updated.') : __('Ledger account created.'));
        $this->resetAccountForm();
    }

    public function editAccount(int $id): void
    {
        $this->authorizeFinanceSettings();

        $account = LedgerAccount::query()->findOrFail($id);
        $this->account_editing_id = (int) $account->id;
        $this->account_company_id = $account->company_id ? (int) $account->company_id : $this->selected_company_id;
        $this->parent_account_id = $account->parent_account_id ? (int) $account->parent_account_id : null;
        $this->account_code = (string) $account->code;
        $this->account_name = (string) $account->name;
        $this->account_type = (string) $account->type;
        $this->account_class = $account->account_class;
        $this->account_detail_type = $account->detail_type;
        $this->account_is_active = (bool) $account->is_active;
        $this->account_allow_direct_posting = (bool) $account->allow_direct_posting;
    }

    public function toggleAccountActive(int $id, AccountingAuditLogService $auditLog): void
    {
        $this->authorizeFinanceSettings();

        $account = LedgerAccount::query()->findOrFail($id);
        $next = ! $account->is_active;

        if (! $next && $this->accountIsProtected($account)) {
            throw ValidationException::withMessages([
                'account' => __('This account is mapped, linked to a bank account, or has posted activity and cannot be deactivated.'),
            ]);
        }

        $account->update(['is_active' => $next]);
        $auditLog->log('settings.ledger_account.status_changed', (int) Auth::id(), $account, ['is_active' => $next], (int) ($account->company_id ?? $this->selected_company_id));
    }

    public function saveMappings(LedgerAccountMappingService $mappingService, AccountingAuditLogService $auditLog): void
    {
        $this->authorizeFinanceSettings();

        if (! $this->selected_company_id) {
            throw ValidationException::withMessages(['selected_company_id' => __('Select a company first.')]);
        }

        $rules = [];
        foreach (array_keys($mappingService->definitions()) as $key) {
            $rules["mapping_values.$key"] = ['nullable', 'integer', 'exists:ledger_accounts,id'];
        }

        $data = $this->validate($rules);

        foreach ($mappingService->definitions() as $key => $definition) {
            $accountId = (int) ($data['mapping_values'][$key] ?? 0);
            if ($definition['required'] && $accountId <= 0) {
                throw ValidationException::withMessages([
                    "mapping_values.$key" => __('This mapping is required.'),
                ]);
            }

            if ($accountId > 0) {
                AccountingAccountMapping::query()->updateOrCreate(
                    ['company_id' => $this->selected_company_id, 'mapping_key' => $key],
                    ['ledger_account_id' => $accountId]
                );
            }
        }

        $auditLog->log('settings.account_mappings.updated', (int) Auth::id(), 'account_mappings', ['company_id' => $this->selected_company_id], (int) $this->selected_company_id);
        $mappingService->bootstrapCompanyMappings($this->selected_company_id);
        session()->flash('status', __('Posting mappings updated.'));
    }

    public function saveBankAccount(AccountingAuditLogService $auditLog): void
    {
        $this->authorizeFinanceSettings();

        $data = $this->validate([
            'bank_company_id' => ['required', 'integer', 'exists:accounting_companies,id'],
            'bank_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'bank_ledger_account_id' => ['required', 'integer', 'exists:ledger_accounts,id'],
            'bank_name' => ['required', 'string', 'max:120'],
            'bank_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('bank_accounts', 'code')
                    ->where(fn ($query) => $query->where('company_id', $this->bank_company_id))
                    ->ignore($this->bank_editing_id),
            ],
            'bank_type' => ['required', 'string', 'max:30'],
            'bank_institution_name' => ['nullable', 'string', 'max:120'],
            'bank_last4' => ['nullable', 'string', 'max:8'],
            'bank_currency' => ['required', 'string', 'max:10'],
            'bank_is_default' => ['required', 'boolean'],
            'bank_is_active' => ['required', 'boolean'],
            'bank_opening_balance' => ['required', 'numeric'],
            'bank_opening_balance_date' => ['nullable', 'date'],
        ]);

        if ($data['bank_is_default']) {
            BankAccount::query()->where('company_id', $data['bank_company_id'])->update(['is_default' => false]);
        }

        $attributes = [
            'company_id' => $data['bank_company_id'],
            'branch_id' => $data['bank_branch_id'] ?: null,
            'ledger_account_id' => $data['bank_ledger_account_id'],
            'name' => trim($data['bank_name']),
            'code' => strtoupper(trim($data['bank_code'])),
            'account_type' => trim($data['bank_type']),
            'bank_name' => $data['bank_institution_name'] ? trim($data['bank_institution_name']) : null,
            'account_number_last4' => $data['bank_last4'] ? trim($data['bank_last4']) : null,
            'currency_code' => strtoupper(trim($data['bank_currency'])),
            'is_default' => (bool) $data['bank_is_default'],
            'is_active' => (bool) $data['bank_is_active'],
            'opening_balance' => round((float) $data['bank_opening_balance'], 2),
            'opening_balance_date' => $data['bank_opening_balance_date'] ?: null,
        ];

        $bank = $this->bank_editing_id
            ? tap(BankAccount::query()->findOrFail($this->bank_editing_id))->update($attributes)
            : BankAccount::query()->create($attributes);

        $bank = $bank->fresh();
        $auditLog->log(
            $this->bank_editing_id ? 'settings.bank_account.updated' : 'settings.bank_account.created',
            (int) Auth::id(),
            $bank,
            ['code' => $bank->code, 'ledger_account_id' => (int) $bank->ledger_account_id],
            (int) $bank->company_id
        );

        session()->flash('status', $this->bank_editing_id ? __('Bank account updated.') : __('Bank account created.'));
        $this->resetBankForm();
    }

    public function editBankAccount(int $id): void
    {
        $this->authorizeFinanceSettings();

        $bank = BankAccount::query()->findOrFail($id);
        $this->bank_editing_id = (int) $bank->id;
        $this->bank_company_id = (int) $bank->company_id;
        $this->bank_branch_id = $bank->branch_id ? (int) $bank->branch_id : null;
        $this->bank_ledger_account_id = $bank->ledger_account_id ? (int) $bank->ledger_account_id : null;
        $this->bank_name = (string) $bank->name;
        $this->bank_code = (string) $bank->code;
        $this->bank_type = (string) $bank->account_type;
        $this->bank_institution_name = $bank->bank_name;
        $this->bank_last4 = $bank->account_number_last4;
        $this->bank_currency = (string) $bank->currency_code;
        $this->bank_is_default = (bool) $bank->is_default;
        $this->bank_is_active = (bool) $bank->is_active;
        $this->bank_opening_balance = number_format((float) $bank->opening_balance, 2, '.', '');
        $this->bank_opening_balance_date = $bank->opening_balance_date?->format('Y-m-d');
    }

    public function toggleBankAccountActive(int $id, AccountingAuditLogService $auditLog): void
    {
        $this->authorizeFinanceSettings();

        $bank = BankAccount::query()->findOrFail($id);
        $next = ! $bank->is_active;
        if (! $next && $bank->is_default) {
            throw ValidationException::withMessages([
                'bank_account' => __('The default bank account cannot be deactivated.'),
            ]);
        }

        $bank->update(['is_active' => $next]);
        $auditLog->log('settings.bank_account.status_changed', (int) Auth::id(), $bank, ['is_active' => $next], (int) $bank->company_id);
    }

    private function hydrateMappingValues(LedgerAccountMappingService $mappingService): void
    {
        $rows = $mappingService->mappingsForCompany($this->selected_company_id);
        $values = [];
        foreach ($mappingService->definitions() as $key => $definition) {
            $values[$key] = $rows->firstWhere('mapping_key', $key)?->ledger_account_id;
        }

        $this->mapping_values = $values;
    }

    private function accountIsProtected(LedgerAccount $account): bool
    {
        if (Schema::hasTable('accounting_account_mappings') && AccountingAccountMapping::query()->where('ledger_account_id', $account->id)->exists()) {
            return true;
        }

        if (Schema::hasTable('bank_accounts') && BankAccount::query()->where('ledger_account_id', $account->id)->exists()) {
            return true;
        }

        return Schema::hasTable('subledger_lines')
            && DB::table('subledger_lines')->where('account_id', $account->id)->exists();
    }

    public function resetAccountForm(): void
    {
        $this->account_editing_id = null;
        $this->account_company_id = $this->selected_company_id;
        $this->parent_account_id = null;
        $this->account_code = '';
        $this->account_name = '';
        $this->account_type = 'asset';
        $this->account_class = null;
        $this->account_detail_type = null;
        $this->account_is_active = true;
        $this->account_allow_direct_posting = true;
    }

    public function resetBankForm(): void
    {
        $this->bank_editing_id = null;
        $this->bank_company_id = $this->selected_company_id;
        $this->bank_branch_id = null;
        $this->bank_ledger_account_id = null;
        $this->bank_name = '';
        $this->bank_code = '';
        $this->bank_type = 'checking';
        $this->bank_institution_name = null;
        $this->bank_last4 = null;
        $this->bank_currency = AccountingCompany::query()->whereKey($this->selected_company_id)->value('base_currency') ?? 'QAR';
        $this->bank_is_default = false;
        $this->bank_is_active = true;
        $this->bank_opening_balance = '0.00';
        $this->bank_opening_balance_date = now()->startOfYear()->toDateString();
    }

    private function tabs(): array
    {
        return [
            'chart' => __('Chart of Accounts'),
            'mappings' => __('Posting Mappings'),
            'banks' => __('Bank & Settlement Accounts'),
        ];
    }

    private function authorizeFinanceSettings(): void
    {
        $user = Auth::user();

        if (! $user || (! $user->hasRole('admin') && ! $user->hasRole('manager') && ! $user->can('finance.access'))) {
            abort(403);
        }
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Accounting Setup')" :subheading="__('Manage the chart of accounts, posting mappings, and bank-linked settlement accounts.')">
        @if(session('status'))
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                {{ session('status') }}
            </div>
        @endif

        <div class="mt-6 space-y-6">
            <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_220px]">
                <div class="flex flex-wrap gap-2">
                    @foreach ($tabs as $tabKey => $label)
                        <a href="{{ route('settings.accounting', ['tab' => $tabKey]) }}" wire:navigate class="{{ $tab === $tabKey ? 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900' : 'bg-white text-neutral-700 dark:bg-neutral-900 dark:text-neutral-200' }} rounded-md border border-neutral-200 px-3 py-2 text-sm font-medium shadow-sm dark:border-neutral-700">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Company') }}</label>
                    <select wire:model.live="selected_company_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        @foreach ($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($tab === 'chart')
                <div class="grid gap-6 xl:grid-cols-[340px_minmax(0,1fr)]">
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                        <div>
                            <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $account_editing_id ? __('Edit Account') : __('New Account') }}</h3>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Codes are unique across the ledger and drive posting/reporting.') }}</p>
                        </div>

                        <div class="grid gap-4">
                            <div>
                                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Company') }}</label>
                                <select wire:model="account_company_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    @foreach ($companies as $company)
                                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <flux:input wire:model="account_code" :label="__('Code')" />
                            <flux:input wire:model="account_name" :label="__('Name')" />
                            <div>
                                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Type') }}</label>
                                <select wire:model="account_type" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="asset">{{ __('Asset') }}</option>
                                    <option value="liability">{{ __('Liability') }}</option>
                                    <option value="equity">{{ __('Equity') }}</option>
                                    <option value="income">{{ __('Income') }}</option>
                                    <option value="expense">{{ __('Expense') }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Parent Account') }}</label>
                                <select wire:model="parent_account_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('None') }}</option>
                                    @foreach ($parentAccountOptions as $account)
                                        <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <flux:input wire:model="account_class" :label="__('Account Class')" />
                            <flux:input wire:model="account_detail_type" :label="__('Detail Type')" />
                            <label class="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200"><input type="checkbox" wire:model="account_allow_direct_posting" class="rounded border-neutral-300 text-primary-600 shadow-sm focus:ring-primary-500"> {{ __('Allow direct posting') }}</label>
                            <label class="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200"><input type="checkbox" wire:model="account_is_active" class="rounded border-neutral-300 text-primary-600 shadow-sm focus:ring-primary-500"> {{ __('Active') }}</label>
                        </div>

                        @error('account') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                        <div class="flex justify-end gap-2">
                            @if($account_editing_id)
                                <flux:button type="button" wire:click="resetAccountForm" variant="ghost">{{ __('Cancel') }}</flux:button>
                            @endif
                            <flux:button type="button" wire:click="saveAccount">{{ $account_editing_id ? __('Update Account') : __('Create Account') }}</flux:button>
                        </div>
                    </div>

                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="app-table-shell">
                            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Code') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Name') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Company') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Posting') }}</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                    @forelse ($accounts as $account)
                                        <tr>
                                            <td class="px-3 py-2 text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $account->code }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $account->name }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $account->company?->name ?? __('Shared') }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ ucfirst($account->type) }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $account->allow_direct_posting ? __('Allowed') : __('Controlled') }}</td>
                                            <td class="px-3 py-2 text-right text-sm">
                                                <div class="flex justify-end gap-2">
                                                    <flux:button size="xs" variant="ghost" wire:click="editAccount({{ $account->id }})">{{ __('Edit') }}</flux:button>
                                                    <flux:button size="xs" variant="ghost" wire:click="toggleAccountActive({{ $account->id }})">{{ $account->is_active ? __('Deactivate') : __('Activate') }}</flux:button>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No ledger accounts found.') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @elseif ($tab === 'mappings')
                <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Posting Mappings') }}</h3>
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('These mappings decide which ledger account each accounting flow uses. The debit and credit rules remain fixed in code.') }}</p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach ($mappingDefinitions as $key => $definition)
                            <div>
                                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ $definition['label'] }}</label>
                                <select wire:model="mapping_values.{{ $key }}" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('Select account') }}</option>
                                    @foreach ($companyAccounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ $definition['description'] }}</p>
                                @error("mapping_values.$key") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>

                    <div class="flex justify-end">
                        <flux:button type="button" wire:click="saveMappings">{{ __('Save Mappings') }}</flux:button>
                    </div>
                </div>
            @else
                <div class="grid gap-6 xl:grid-cols-[340px_minmax(0,1fr)]">
                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                        <div>
                            <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $bank_editing_id ? __('Edit Bank Account') : __('New Bank Account') }}</h3>
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Each bank account must be linked to a ledger account and is used for bank-transfer posting and reconciliation.') }}</p>
                        </div>

                        <div class="grid gap-4">
                            <div>
                                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Company') }}</label>
                                <select wire:model="bank_company_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    @foreach ($companies as $company)
                                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
                                <select wire:model="bank_branch_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('None') }}</option>
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Linked Ledger Account') }}</label>
                                <select wire:model="bank_ledger_account_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                    <option value="">{{ __('Select account') }}</option>
                                    @foreach ($companyAccounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <flux:input wire:model="bank_name" :label="__('Display Name')" />
                            <flux:input wire:model="bank_code" :label="__('Code')" />
                            <flux:input wire:model="bank_type" :label="__('Account Type')" />
                            <flux:input wire:model="bank_institution_name" :label="__('Bank Name')" />
                            <flux:input wire:model="bank_last4" :label="__('Account Number Last 4')" />
                            <flux:input wire:model="bank_currency" :label="__('Currency')" />
                            <flux:input wire:model="bank_opening_balance" type="number" step="0.01" :label="__('Opening Balance')" />
                            <flux:input wire:model="bank_opening_balance_date" type="date" :label="__('Opening Balance Date')" />
                            <label class="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200"><input type="checkbox" wire:model="bank_is_default" class="rounded border-neutral-300 text-primary-600 shadow-sm focus:ring-primary-500"> {{ __('Default bank account') }}</label>
                            <label class="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200"><input type="checkbox" wire:model="bank_is_active" class="rounded border-neutral-300 text-primary-600 shadow-sm focus:ring-primary-500"> {{ __('Active') }}</label>
                        </div>

                        @error('bank_account') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                        <div class="flex justify-end gap-2">
                            @if($bank_editing_id)
                                <flux:button type="button" wire:click="resetBankForm" variant="ghost">{{ __('Cancel') }}</flux:button>
                            @endif
                            <flux:button type="button" wire:click="saveBankAccount">{{ $bank_editing_id ? __('Update Bank Account') : __('Create Bank Account') }}</flux:button>
                        </div>
                    </div>

                    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="app-table-shell">
                            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                                    <tr>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Name') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Ledger') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Default') }}</th>
                                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                                    @forelse ($bankAccounts as $account)
                                        <tr>
                                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $account->name }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $account->ledgerAccount?->code }} · {{ $account->ledgerAccount?->name ?? '—' }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $account->account_type }}</td>
                                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $account->is_default ? __('Yes') : __('No') }}</td>
                                            <td class="px-3 py-2 text-right text-sm">
                                                <div class="flex justify-end gap-2">
                                                    <flux:button size="xs" variant="ghost" wire:click="editBankAccount({{ $account->id }})">{{ __('Edit') }}</flux:button>
                                                    <flux:button size="xs" variant="ghost" wire:click="toggleBankAccountActive({{ $account->id }})">{{ $account->is_active ? __('Deactivate') : __('Activate') }}</flux:button>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No bank accounts found.') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </x-settings.layout>
</section>

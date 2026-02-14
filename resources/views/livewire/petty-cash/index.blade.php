<?php

use App\Models\ExpenseCategory;
use App\Models\PettyCashExpense;
use App\Models\PettyCashIssue;
use App\Models\PettyCashReconciliation;
use App\Models\PettyCashWallet;
use App\Services\PettyCash\PettyCashExpenseWorkflowService;
use App\Services\PettyCash\PettyCashIssueService;
use App\Services\PettyCash\PettyCashReconciliationService;
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

        return PettyCashIssue::with('wallet')
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

        return PettyCashReconciliation::with(['wallet', 'reconciler'])
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
        $service->approve($expense, Auth::id());
        session()->flash('status', __('Expense approved.'));
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

    private function authorizeManager(): void
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (
            ! $user
            || (! $user->hasRole('admin') && ! $user->hasRole('manager') && ! $user->can('finance.access'))
        ) {
            abort(403);
        }
    }
}; ?>

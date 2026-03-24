<?php

use App\Models\AccountingCompany;
use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Job;
use App\Models\RecurringBillTemplate;
use App\Models\Supplier;
use App\Services\AP\ApInvoicePostingService;
use App\Services\AP\ApInvoiceVoidService;
use App\Services\AP\ApReportsService;
use App\Services\AP\RecurringBillService;
use App\Services\Spend\ExpenseWorkflowService;
use App\Support\AP\DocumentTypeMap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $tab = 'all';
    public string $document_type = 'all';
    public string $approval_status = 'all';
    public string $workflow_state = 'all';
    public string $payment_state = 'all';
    public string $expense_channel = 'all';
    public ?int $supplier_id = null;
    public ?int $branch_id = null;
    public ?int $department_id = null;
    public ?int $job_id = null;
    public ?string $date_from = null;
    public ?string $date_to = null;
    public ?string $search = null;

    public ?int $payment_supplier_id = null;
    public ?string $payment_method = null;
    public ?string $payment_date_from = null;
    public ?string $payment_date_to = null;

    public ?int $editing_recurring_template_id = null;
    public ?int $recurring_company_id = null;
    public string $recurring_name = '';
    public ?int $recurring_supplier_id = null;
    public ?int $recurring_branch_id = null;
    public ?int $recurring_department_id = null;
    public ?int $recurring_job_id = null;
    public string $recurring_frequency = 'monthly';
    public ?string $recurring_start_date = null;
    public ?string $recurring_end_date = null;
    public ?string $recurring_next_run_date = null;
    public string $recurring_due_day_offset = '30';
    public bool $recurring_is_active = true;
    public ?string $recurring_notes = null;
    public array $recurring_lines = [];

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $requestedTab = (string) request()->query('tab', 'all');
        $allowedTabs = $this->availableTabs();
        $this->tab = in_array($requestedTab, array_keys($allowedTabs), true) ? $requestedTab : array_key_first($allowedTabs);
        $this->expense_channel = (string) request()->query('expense_channel', 'all');
        $this->resetRecurringTemplateForm();
    }

    public function updating($field): void
    {
        if ($field !== '') {
            $this->resetPage(pageName: 'docPage');
            $this->resetPage(pageName: 'payPage');
        }
    }

    public function submitExpense(int $invoiceId, ExpenseWorkflowService $service): void
    {
        $invoice = ApInvoice::query()->with('expenseProfile')->findOrFail($invoiceId);
        $service->submit($invoice, (int) auth()->id());
        session()->flash('status', __('Document submitted.'));
    }

    public function approveManager(int $invoiceId, ExpenseWorkflowService $service): void
    {
        abort_unless($this->canManagerApprove(), 403);
        $invoice = ApInvoice::query()->with('expenseProfile')->findOrFail($invoiceId);
        $service->approve($invoice, (int) auth()->id(), 'manager');
        session()->flash('status', __('Manager approval recorded.'));
    }

    public function approveFinance(int $invoiceId, ExpenseWorkflowService $service): void
    {
        abort_unless($this->canFinanceApprove(), 403);
        $invoice = ApInvoice::query()->with('expenseProfile')->findOrFail($invoiceId);
        $service->approve($invoice, (int) auth()->id(), 'finance');
        session()->flash('status', __('Finance approval recorded.'));
    }

    public function rejectExpense(int $invoiceId, ExpenseWorkflowService $service): void
    {
        abort_unless($this->canManagerApprove() || $this->canFinanceApprove(), 403);
        $invoice = ApInvoice::query()->with('expenseProfile')->findOrFail($invoiceId);
        $service->reject($invoice, (int) auth()->id(), 'Rejected from AP workspace');
        session()->flash('status', __('Document rejected.'));
    }

    public function postDocument(int $invoiceId, ApInvoicePostingService $postingService, ExpenseWorkflowService $expenseWorkflowService): void
    {
        $invoice = ApInvoice::query()->with('expenseProfile')->findOrFail($invoiceId);

        if ($invoice->is_expense) {
            abort_unless($this->canFinanceApprove(), 403);
            $expenseWorkflowService->post($invoice, (int) auth()->id());
            session()->flash('status', __('Expense posted.'));

            return;
        }

        abort_unless($this->canManageAp(), 403);
        $postingService->post($invoice, (int) auth()->id());
        session()->flash('status', __('Bill posted.'));
    }

    public function settleExpense(int $invoiceId, ExpenseWorkflowService $service): void
    {
        abort_unless($this->canFinanceApprove(), 403);
        $invoice = ApInvoice::query()->with('expenseProfile')->findOrFail($invoiceId);
        $service->settle($invoice, (int) auth()->id());
        session()->flash('status', __('Expense settled.'));
    }

    public function voidDocument(int $invoiceId, ApInvoiceVoidService $voidService): void
    {
        abort_unless($this->canManageAp(), 403);
        $invoice = ApInvoice::query()->with('allocations')->findOrFail($invoiceId);
        $voidService->void($invoice, (int) auth()->id());
        session()->flash('status', __('Document voided.'));
    }

    public function addRecurringLine(): void
    {
        $this->recurring_lines[] = ['description' => '', 'quantity' => 1, 'unit_price' => 0];
    }

    public function removeRecurringLine(int $index): void
    {
        unset($this->recurring_lines[$index]);
        $this->recurring_lines = array_values($this->recurring_lines);
    }

    public function editRecurringTemplate(int $templateId): void
    {
        abort_unless($this->canManageAp(), 403);
        $template = RecurringBillTemplate::query()->with('lines')->findOrFail($templateId);

        $this->editing_recurring_template_id = $template->id;
        $this->recurring_name = $template->name;
        $this->recurring_company_id = $template->company_id;
        $this->recurring_supplier_id = $template->supplier_id;
        $this->recurring_branch_id = $template->branch_id;
        $this->recurring_department_id = $template->department_id;
        $this->recurring_job_id = $template->job_id;
        $this->recurring_frequency = $template->frequency;
        $this->recurring_start_date = optional($template->start_date)->toDateString();
        $this->recurring_end_date = optional($template->end_date)->toDateString();
        $this->recurring_next_run_date = optional($template->next_run_date)->toDateString();
        $this->recurring_due_day_offset = (string) ($template->due_day_offset ?? 30);
        $this->recurring_is_active = (bool) $template->is_active;
        $this->recurring_notes = $template->notes;
        $this->recurring_lines = $template->lines->map(fn ($line) => [
            'purchase_order_item_id' => $line->purchase_order_item_id,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
        ])->all();
    }

    public function saveRecurringTemplate(RecurringBillService $service): void
    {
        abort_unless($this->canManageAp(), 403);

        $data = $this->validate([
            'recurring_name' => ['required', 'string', 'max:120'],
            'recurring_company_id' => ['required', 'integer', 'exists:accounting_companies,id'],
            'recurring_supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'recurring_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'recurring_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'recurring_job_id' => ['nullable', 'integer', 'exists:accounting_jobs,id'],
            'recurring_frequency' => ['required', 'in:weekly,monthly,quarterly,annual'],
            'recurring_start_date' => ['nullable', 'date'],
            'recurring_end_date' => ['nullable', 'date', 'after_or_equal:recurring_start_date'],
            'recurring_next_run_date' => ['required', 'date'],
            'recurring_due_day_offset' => ['required', 'integer', 'min:0'],
            'recurring_is_active' => ['boolean'],
            'recurring_notes' => ['nullable', 'string'],
            'recurring_lines' => ['required', 'array', 'min:1'],
            'recurring_lines.*.description' => ['required', 'string', 'max:255'],
            'recurring_lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'recurring_lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $template = $this->editing_recurring_template_id
            ? RecurringBillTemplate::query()->findOrFail($this->editing_recurring_template_id)
            : null;

        $service->saveTemplate([
            'company_id' => $data['recurring_company_id'],
            'branch_id' => $data['recurring_branch_id'],
            'supplier_id' => $data['recurring_supplier_id'],
            'department_id' => $data['recurring_department_id'],
            'job_id' => $data['recurring_job_id'],
            'name' => $data['recurring_name'],
            'frequency' => $data['recurring_frequency'],
            'start_date' => $data['recurring_start_date'],
            'end_date' => $data['recurring_end_date'],
            'next_run_date' => $data['recurring_next_run_date'],
            'due_day_offset' => $data['recurring_due_day_offset'],
            'is_active' => $data['recurring_is_active'],
            'notes' => $data['recurring_notes'],
            'lines' => $data['recurring_lines'],
        ], (int) auth()->id(), $template);

        $this->resetRecurringTemplateForm();
        session()->flash('status', __('Recurring bill template saved.'));
    }

    public function pauseRecurringTemplate(int $templateId, RecurringBillService $service): void
    {
        abort_unless($this->canManageAp(), 403);
        $service->pause(RecurringBillTemplate::query()->findOrFail($templateId), (int) auth()->id());
        session()->flash('status', __('Recurring bill template paused.'));
    }

    public function resumeRecurringTemplate(int $templateId, RecurringBillService $service): void
    {
        abort_unless($this->canManageAp(), 403);
        $service->resume(RecurringBillTemplate::query()->findOrFail($templateId), (int) auth()->id());
        session()->flash('status', __('Recurring bill template resumed.'));
    }

    public function generateRecurringTemplate(int $templateId, RecurringBillService $service): void
    {
        abort_unless($this->canManageAp(), 403);
        $invoice = $service->generateTemplate(RecurringBillTemplate::query()->findOrFail($templateId), null, (int) auth()->id());
        session()->flash('status', __('Recurring bill draft generated.'));
        $this->redirectRoute('payables.invoices.show', $invoice, navigate: true);
    }

    public function resetRecurringTemplateForm(): void
    {
        $this->editing_recurring_template_id = null;
        $this->recurring_company_id = AccountingCompany::query()->where('is_default', true)->value('id');
        $this->recurring_name = '';
        $this->recurring_supplier_id = null;
        $this->recurring_branch_id = null;
        $this->recurring_department_id = null;
        $this->recurring_job_id = null;
        $this->recurring_frequency = 'monthly';
        $this->recurring_start_date = now()->toDateString();
        $this->recurring_end_date = null;
        $this->recurring_next_run_date = now()->toDateString();
        $this->recurring_due_day_offset = '30';
        $this->recurring_is_active = true;
        $this->recurring_notes = null;
        $this->recurring_lines = [['description' => '', 'quantity' => 1, 'unit_price' => 0]];
    }

    public function with(): array
    {
        $companies = Schema::hasTable('accounting_companies') ? AccountingCompany::query()->orderBy('name')->get() : collect();
        $suppliers = Schema::hasTable('suppliers') ? Supplier::query()->orderBy('name')->get() : collect();
        $branches = Schema::hasTable('branches') ? Branch::query()->orderBy('name')->get() : collect();
        $departments = Schema::hasTable('departments') ? Department::query()->orderBy('name')->get() : collect();
        $jobs = Schema::hasTable('accounting_jobs') ? Job::query()->orderBy('code')->get() : collect();

        return [
            'tabs' => $this->availableTabs(),
            'companies' => $companies,
            'suppliers' => $suppliers,
            'branches' => $branches,
            'departments' => $departments,
            'jobs' => $jobs,
            'documentTypeLabels' => DocumentTypeMap::labels(),
            'documentPage' => $this->tab === 'payments' || $this->tab === 'aging'
                ? null
                : $this->documentQuery()->paginate(12, pageName: 'docPage'),
            'paymentPage' => $this->tab === 'payments'
                ? $this->paymentQuery()->paginate(10, pageName: 'payPage')
                : null,
            'recurringTemplates' => $this->tab === 'recurring' && $this->canManageAp()
                ? RecurringBillTemplate::query()->with(['company', 'supplier', 'lines', 'generatedInvoices'])->latest('updated_at')->get()
                : collect(),
            'aging' => $this->canManageAp() ? app(ApReportsService::class)->agingSummary($this->supplier_id) : null,
            'agingInvoices' => $this->tab === 'aging' && $this->canManageAp()
                ? ApInvoice::query()
                    ->with(['supplier', 'expenseProfile'])
                    ->withSum('allocations as paid_sum', 'allocated_amount')
                    ->whereNotIn('status', ['void', 'paid'])
                    ->orderByDesc('due_date')
                    ->limit(100)
                    ->get()
                : collect(),
        ];
    }

    private function documentQuery(): Builder
    {
        $query = ApInvoice::query()
            ->with(['supplier', 'category', 'expenseProfile.wallet'])
            ->withSum('allocations as paid_sum', 'allocated_amount');

        $query
            ->when($this->supplier_id, fn (Builder $q) => $q->where('supplier_id', $this->supplier_id))
            ->when($this->branch_id, fn (Builder $q) => $q->where('branch_id', $this->branch_id))
            ->when($this->department_id, fn (Builder $q) => $q->where('department_id', $this->department_id))
            ->when($this->job_id, fn (Builder $q) => $q->where('job_id', $this->job_id))
            ->when($this->date_from, fn (Builder $q) => $q->whereDate('invoice_date', '>=', $this->date_from))
            ->when($this->date_to, fn (Builder $q) => $q->whereDate('invoice_date', '<=', $this->date_to))
            ->when($this->search, function (Builder $q) {
                $search = '%'.trim((string) $this->search).'%';
                $q->where(function (Builder $sub) use ($search) {
                    $sub->where('invoice_number', 'like', $search)
                        ->orWhere('notes', 'like', $search)
                        ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $search));
                });
            })
            ->when($this->document_type !== 'all', fn (Builder $q) => $q->where('document_type', $this->document_type))
            ->when($this->approval_status !== 'all', fn (Builder $q) => $q->whereHas('expenseProfile', fn (Builder $profile) => $profile->where('approval_status', $this->approval_status)))
            ->when($this->expense_channel !== 'all', fn (Builder $q) => $q->whereHas('expenseProfile', fn (Builder $profile) => $profile->where('channel', $this->expense_channel)));

        $this->applyTabFilter($query);
        $this->applyWorkflowStateFilter($query);
        $this->applyPaymentStateFilter($query);

        return $query->orderByDesc('invoice_date')->orderByDesc('id');
    }

    private function applyTabFilter(Builder $query): void
    {
        match ($this->tab) {
            'bills' => $query->where('is_expense', false),
            'expenses' => $query->where('document_type', 'expense'),
            'reimbursements' => $query->where('document_type', 'reimbursement'),
            'approvals' => $query->where('is_expense', true)
                ->whereHas('expenseProfile', fn (Builder $profile) => $profile->whereIn('approval_status', ['draft', 'submitted', 'manager_approved', 'approved'])),
            default => null,
        };
    }

    private function applyWorkflowStateFilter(Builder $query): void
    {
        match ($this->workflow_state) {
            'draft' => $query->where('status', 'draft')->where(function (Builder $sub) {
                $sub->where('is_expense', false)
                    ->orWhereHas('expenseProfile', fn (Builder $profile) => $profile->where('approval_status', 'draft'));
            }),
            'submitted' => $query->whereHas('expenseProfile', fn (Builder $profile) => $profile->where('approval_status', 'submitted')),
            'manager_approved' => $query->whereHas('expenseProfile', fn (Builder $profile) => $profile->where('approval_status', 'manager_approved')),
            'approved_pending_post' => $query->where('is_expense', true)->where('status', 'draft')
                ->whereHas('expenseProfile', fn (Builder $profile) => $profile->where('approval_status', 'approved')),
            'posted' => $query->where('status', 'posted'),
            'posted_pending_settlement' => $query->where('is_expense', true)
                ->whereIn('status', ['posted', 'partially_paid'])
                ->whereHas('expenseProfile', fn (Builder $profile) => $profile->whereNull('settled_at')),
            'partially_paid' => $query->where('status', 'partially_paid'),
            'closed' => $query->where('status', 'paid'),
            'rejected' => $query->whereHas('expenseProfile', fn (Builder $profile) => $profile->where('approval_status', 'rejected')),
            'void' => $query->where('status', 'void'),
            default => null,
        };
    }

    private function applyPaymentStateFilter(Builder $query): void
    {
        match ($this->payment_state) {
            'pending' => $query->where('status', 'draft'),
            'open' => $query->where('status', 'posted'),
            'partially_paid' => $query->where('status', 'partially_paid'),
            'paid' => $query->where('status', 'paid'),
            'settled' => $query->where('is_expense', true)
                ->whereHas('expenseProfile', fn (Builder $profile) => $profile->whereNotNull('settled_at')),
            'void' => $query->where('status', 'void'),
            default => null,
        };
    }

    private function paymentQuery(): Builder
    {
        return ApPayment::query()
            ->with(['supplier'])
            ->withSum('allocations as alloc_sum', 'allocated_amount')
            ->when($this->payment_supplier_id, fn (Builder $q) => $q->where('supplier_id', $this->payment_supplier_id))
            ->when($this->payment_method, fn (Builder $q) => $q->where('payment_method', $this->payment_method))
            ->when($this->payment_date_from, fn (Builder $q) => $q->whereDate('payment_date', '>=', $this->payment_date_from))
            ->when($this->payment_date_to, fn (Builder $q) => $q->whereDate('payment_date', '<=', $this->payment_date_to))
            ->orderByDesc('payment_date');
    }

    private function availableTabs(): array
    {
        $tabs = [
            'all' => __('All'),
            'bills' => __('Bills'),
            'expenses' => __('Expenses'),
            'reimbursements' => __('Reimbursements'),
            'approvals' => __('Approvals'),
        ];

        if ($this->canManageAp()) {
            $tabs['recurring'] = __('Recurring Bills');
            $tabs['payments'] = __('Payments');
            $tabs['aging'] = __('Aging');
        }

        return $tabs;
    }

    public function canManageAp(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasAnyRole(['admin', 'manager']) || $user?->can('finance.access'));
    }

    public function canManagerApprove(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasAnyRole(['admin', 'manager']));
    }

    public function canFinanceApprove(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasRole('admin') || $user?->can('finance.access'));
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Accounts Payable') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Bills, expenses, reimbursements, approvals, payments, and aging in one finance workspace.') }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('payables.create')" wire:navigate>{{ __('New Document') }}</flux:button>
            @if ($this->canManageAp())
                <flux:button :href="route('payables.payments.create')" wire:navigate variant="ghost">{{ __('New Payment') }}</flux:button>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="flex flex-wrap gap-3">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" class="rounded-md px-3 py-2 text-sm font-semibold {{ $tab === $key ? 'bg-neutral-200 dark:bg-neutral-700' : 'bg-neutral-100 dark:bg-neutral-800' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($tab !== 'payments' && $tab !== 'aging' && $tab !== 'recurring')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" :placeholder="__('Document #, supplier, notes')" />
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                    <select wire:model.live="supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All suppliers') }}</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Document Type') }}</label>
                    <select wire:model.live="document_type" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="all">{{ __('All types') }}</option>
                        @foreach ($documentTypeLabels as $type => $label)
                            <option value="{{ $type }}">{{ __($label) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Approval') }}</label>
                    <select wire:model.live="approval_status" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="all">{{ __('All approval states') }}</option>
                        <option value="draft">{{ __('Draft') }}</option>
                        <option value="submitted">{{ __('Submitted') }}</option>
                        <option value="manager_approved">{{ __('Manager Approved') }}</option>
                        <option value="approved">{{ __('Finance Approved') }}</option>
                        <option value="rejected">{{ __('Rejected') }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Workflow') }}</label>
                    <select wire:model.live="workflow_state" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="all">{{ __('All workflow states') }}</option>
                        <option value="draft">{{ __('Draft') }}</option>
                        <option value="submitted">{{ __('Submitted') }}</option>
                        <option value="manager_approved">{{ __('Manager Approved') }}</option>
                        <option value="approved_pending_post">{{ __('Approved Pending Post') }}</option>
                        <option value="posted">{{ __('Posted') }}</option>
                        <option value="posted_pending_settlement">{{ __('Posted Pending Settlement') }}</option>
                        <option value="partially_paid">{{ __('Partially Paid') }}</option>
                        <option value="closed">{{ __('Closed') }}</option>
                        <option value="rejected">{{ __('Rejected') }}</option>
                        <option value="void">{{ __('Void') }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Payment') }}</label>
                    <select wire:model.live="payment_state" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="all">{{ __('All payment states') }}</option>
                        <option value="pending">{{ __('Pending') }}</option>
                        <option value="open">{{ __('Open') }}</option>
                        <option value="partially_paid">{{ __('Partially Paid') }}</option>
                        <option value="settled">{{ __('Settled') }}</option>
                        <option value="paid">{{ __('Paid') }}</option>
                        <option value="void">{{ __('Void') }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Expense Channel') }}</label>
                    <select wire:model.live="expense_channel" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="all">{{ __('All channels') }}</option>
                        <option value="vendor">{{ __('Vendor') }}</option>
                        <option value="petty_cash">{{ __('Petty Cash') }}</option>
                        <option value="reimbursement">{{ __('Reimbursement') }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Date From') }}</label>
                    <input wire:model.live="date_from" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Date To') }}</label>
                    <input wire:model.live="date_to" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
                    <select wire:model.live="branch_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All branches') }}</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Department') }}</label>
                    <select wire:model.live="department_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All departments') }}</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Job') }}</label>
                    <select wire:model.live="job_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All jobs') }}</option>
                        @foreach ($jobs as $job)
                            <option value="{{ $job->id }}">{{ $job->code }} · {{ $job->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Document') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Counterparty') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Approval') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Accounting') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Payment') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse($documentPage as $invoice)
                            @php
                                $channel = $invoice->expenseProfile?->channel;
                                $counterparty = $channel === 'petty_cash'
                                    ? ($invoice->expenseProfile?->wallet?->driver_name ?: $invoice->expenseProfile?->wallet?->driver_id ?: '—')
                                    : ($invoice->supplier?->name ?? '—');
                            @endphp
                            <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                                    <div class="font-semibold">{{ $invoice->invoice_number }}</div>
                                    <div class="text-xs text-neutral-500">{{ $invoice->invoice_date?->format('Y-m-d') }}</div>
                                </td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $invoice->documentTypeLabel() }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $counterparty }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ Str::headline(str_replace('_', ' ', $invoice->approvalStatusLabel())) }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ Str::headline(str_replace('_', ' ', $invoice->workflowStateLabel())) }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ Str::headline(str_replace('_', ' ', $invoice->paymentStateLabel())) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">
                                    <div>{{ number_format((float) $invoice->total_amount, 2) }}</div>
                                    <div class="text-xs text-neutral-500">{{ __('Open') }}: {{ number_format((float) $invoice->total_amount - (float) $invoice->paid_sum, 2) }}</div>
                                </td>
                                <td class="px-3 py-2 text-sm">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <flux:button size="xs" :href="route('payables.invoices.show', $invoice)" wire:navigate>{{ __('View') }}</flux:button>

                                        @if($invoice->status === 'draft')
                                            <flux:button size="xs" :href="route('payables.invoices.edit', $invoice)" wire:navigate variant="ghost">{{ __('Edit') }}</flux:button>
                                        @endif

                                        @if($invoice->is_expense)
                                            @if($invoice->approvalStatusLabel() === 'draft')
                                                <flux:button size="xs" type="button" wire:click="submitExpense({{ $invoice->id }})">{{ __('Submit') }}</flux:button>
                                            @endif

                                            @if($invoice->approvalStatusLabel() === 'submitted' && $this->canManagerApprove())
                                                <flux:button size="xs" type="button" wire:click="approveManager({{ $invoice->id }})">{{ __('Manager Approve') }}</flux:button>
                                                <flux:button size="xs" type="button" wire:click="rejectExpense({{ $invoice->id }})" variant="ghost">{{ __('Reject') }}</flux:button>
                                            @endif

                                            @if($invoice->approvalStatusLabel() === 'manager_approved' && $this->canFinanceApprove())
                                                <flux:button size="xs" type="button" wire:click="approveFinance({{ $invoice->id }})">{{ __('Finance Approve') }}</flux:button>
                                                <flux:button size="xs" type="button" wire:click="rejectExpense({{ $invoice->id }})" variant="ghost">{{ __('Reject') }}</flux:button>
                                            @endif

                                            @if($invoice->approvalStatusLabel() === 'approved' && $invoice->status === 'draft' && $this->canFinanceApprove())
                                                <flux:button size="xs" type="button" wire:click="postDocument({{ $invoice->id }})">{{ __('Post') }}</flux:button>
                                            @endif

                                            @if(in_array($invoice->status, ['posted', 'partially_paid'], true) && ! $invoice->expenseProfile?->settled_at && $this->canFinanceApprove())
                                                <flux:button size="xs" type="button" wire:click="settleExpense({{ $invoice->id }})">{{ __('Settle') }}</flux:button>
                                            @endif
                                        @else
                                            @if($invoice->status === 'draft' && $this->canManageAp())
                                                <flux:button size="xs" type="button" wire:click="postDocument({{ $invoice->id }})">{{ __('Post') }}</flux:button>
                                            @endif
                                        @endif

                                        @if($invoice->canVoid() && $this->canManageAp())
                                            <flux:button size="xs" type="button" wire:click="voidDocument({{ $invoice->id }})" variant="ghost">{{ __('Void') }}</flux:button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No AP documents found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($documentPage)
                <div class="mt-4">
                    {{ $documentPage->links() }}
                </div>
            @endif
        </div>
    @endif

    @if($tab === 'recurring' && $this->canManageAp())
        <div class="grid gap-6 xl:grid-cols-[360px,1fr]">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="mb-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $editing_recurring_template_id ? __('Edit Recurring Template') : __('New Recurring Template') }}</h2>
                <form wire:submit="saveRecurringTemplate" class="space-y-4">
                    <flux:input wire:model="recurring_name" :label="__('Template Name')" />
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Company') }}</label>
                        <select wire:model="recurring_company_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}">{{ $company->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                        <select wire:model="recurring_supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="">{{ __('Select supplier') }}</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Frequency') }}</label>
                            <select wire:model="recurring_frequency" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                                <option value="weekly">{{ __('Weekly') }}</option>
                                <option value="monthly">{{ __('Monthly') }}</option>
                                <option value="quarterly">{{ __('Quarterly') }}</option>
                                <option value="annual">{{ __('Annual') }}</option>
                            </select>
                        </div>
                        <flux:input wire:model="recurring_due_day_offset" type="number" min="0" :label="__('Due in days')" />
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="recurring_start_date" type="date" :label="__('Start Date')" />
                        <flux:input wire:model="recurring_next_run_date" type="date" :label="__('Next Run')" />
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <flux:input wire:model="recurring_end_date" type="date" :label="__('End Date')" />
                        <label class="mt-7 inline-flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                            <input type="checkbox" wire:model="recurring_is_active" class="rounded border-neutral-300 text-primary-600">
                            {{ __('Active') }}
                        </label>
                    </div>
                    <flux:textarea wire:model="recurring_notes" :label="__('Notes')" rows="2" />

                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Template Lines') }}</h3>
                            <flux:button type="button" wire:click="addRecurringLine" size="sm" variant="ghost">{{ __('Add Line') }}</flux:button>
                        </div>
                        @foreach($recurring_lines as $index => $line)
                            <div class="grid gap-3 md:grid-cols-[1fr,120px,120px,auto] items-end">
                                <flux:input wire:model="recurring_lines.{{ $index }}.description" :label="__('Description')" />
                                <flux:input wire:model="recurring_lines.{{ $index }}.quantity" type="number" step="0.001" :label="__('Qty')" />
                                <flux:input wire:model="recurring_lines.{{ $index }}.unit_price" type="number" step="0.0001" :label="__('Unit Price')" />
                                <flux:button type="button" wire:click="removeRecurringLine({{ $index }})" variant="ghost" size="sm">{{ __('Remove') }}</flux:button>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex justify-end gap-2">
                        @if($editing_recurring_template_id)
                            <flux:button type="button" wire:click="resetRecurringTemplateForm" variant="ghost">{{ __('Cancel') }}</flux:button>
                        @endif
                        <flux:button type="submit">{{ __('Save Template') }}</flux:button>
                    </div>
                </form>
            </div>

            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="app-table-shell">
                    <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                        <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Template') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Company') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Frequency') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Schedule') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                            @forelse($recurringTemplates as $template)
                                <tr>
                                    <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">
                                        <div class="font-semibold">{{ $template->name }}</div>
                                        <div class="text-xs text-neutral-500">{{ $template->lines->count() }} {{ __('lines') }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $template->company?->name ?? '—' }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $template->supplier?->name ?? '—' }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ ucfirst($template->frequency) }}</td>
                                    <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                        <div>{{ __('Next') }}: {{ optional($template->next_run_date)->format('Y-m-d') ?? '—' }}</div>
                                        <div class="text-xs text-neutral-500">{{ __('Last') }}: {{ optional($template->last_run_date)->format('Y-m-d') ?? '—' }}</div>
                                        <div class="text-xs text-neutral-500">{{ $template->is_active ? __('Active') : __('Paused') }}</div>
                                    </td>
                                    <td class="px-3 py-2 text-right text-sm">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            <flux:button type="button" wire:click="editRecurringTemplate({{ $template->id }})" size="xs" variant="ghost">{{ __('Edit') }}</flux:button>
                                            <flux:button type="button" wire:click="generateRecurringTemplate({{ $template->id }})" size="xs">{{ __('Generate') }}</flux:button>
                                            @if($template->is_active)
                                                <flux:button type="button" wire:click="pauseRecurringTemplate({{ $template->id }})" size="xs" variant="ghost">{{ __('Pause') }}</flux:button>
                                            @else
                                                <flux:button type="button" wire:click="resumeRecurringTemplate({{ $template->id }})" size="xs" variant="ghost">{{ __('Resume') }}</flux:button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                <tr class="bg-neutral-50/60 dark:bg-neutral-800/40">
                                    <td colspan="6" class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-medium">{{ __('Recent generated drafts:') }}</span>
                                            @forelse($template->generatedInvoices->take(5) as $generatedInvoice)
                                                <a href="{{ route('payables.invoices.show', $generatedInvoice) }}" class="rounded-full bg-white px-2 py-1 text-xs font-medium text-primary-700 shadow-sm ring-1 ring-neutral-200 hover:underline dark:bg-neutral-900 dark:text-primary-300 dark:ring-neutral-700">
                                                    {{ $generatedInvoice->invoice_number }}
                                                </a>
                                            @empty
                                                <span class="text-xs text-neutral-500">{{ __('No generated drafts yet.') }}</span>
                                            @endforelse
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No recurring bill templates found.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if($tab === 'payments')
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                    <select wire:model.live="payment_supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All suppliers') }}</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Method') }}</label>
                    <select wire:model.live="payment_method" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('All methods') }}</option>
                        <option value="cash">{{ __('Cash') }}</option>
                        <option value="bank_transfer">{{ __('Bank Transfer') }}</option>
                        <option value="card">{{ __('Card') }}</option>
                        <option value="cheque">{{ __('Cheque') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                        <option value="petty_cash">{{ __('Petty Cash') }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Date From') }}</label>
                    <input wire:model.live="payment_date_from" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Date To') }}</label>
                    <input wire:model.live="payment_date_to" type="date" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
                </div>
            </div>

            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Method') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Amount') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Allocated') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse($paymentPage as $payment)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $payment->payment_date?->format('Y-m-d') }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $payment->supplier?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $payment->payment_method ?? '—' }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $payment->amount, 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $payment->alloc_sum, 2) }}</td>
                                <td class="px-3 py-2 text-right text-sm">
                                    <flux:button size="xs" :href="route('payables.payments.show', $payment)" wire:navigate>{{ __('View') }}</flux:button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No payments found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($paymentPage)
                <div class="mt-4">
                    {{ $paymentPage->links() }}
                </div>
            @endif
        </div>
    @endif

    @if($tab === 'aging' && $this->canManageAp())
        <div class="grid gap-4 md:grid-cols-5">
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('Current') }}</p>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($aging['current'] ?? 0), 2) }}</p>
            </div>
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('1-30') }}</p>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($aging['1_30'] ?? 0), 2) }}</p>
            </div>
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('31-60') }}</p>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($aging['31_60'] ?? 0), 2) }}</p>
            </div>
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('61-90') }}</p>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($aging['61_90'] ?? 0), 2) }}</p>
            </div>
            <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <p class="text-xs uppercase tracking-wide text-neutral-500">{{ __('90+') }}</p>
                <p class="mt-2 text-2xl font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float) ($aging['90_plus'] ?? 0), 2) }}</p>
            </div>
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="app-table-shell">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Document') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Due Date') }}</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Outstanding') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse($agingInvoices as $invoice)
                            <tr>
                                <td class="px-3 py-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $invoice->invoice_number }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $invoice->documentTypeLabel() }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $invoice->supplier?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $invoice->due_date?->format('Y-m-d') }}</td>
                                <td class="px-3 py-2 text-right text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float) $invoice->total_amount - (float) $invoice->paid_sum, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No open documents found.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

<?php

use App\Models\ApInvoice;
use App\Services\Spend\ExpenseWorkflowService;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $tab = 'vendor';

    public function mount(): void
    {
        $tab = (string) request()->query('tab', 'vendor');
        $this->tab = in_array($tab, ['vendor', 'petty'], true) ? $tab : 'vendor';
    }

    public function with(): array
    {
        $vendorExpenses = collect();
        $pettyExpenses = collect();

        if (Schema::hasTable('ap_invoices') && Schema::hasTable('expense_profiles')) {
            $vendorExpenses = ApInvoice::query()
                ->where('is_expense', true)
                ->where(function ($q) {
                    $q->whereHas('expenseProfile', fn ($sub) => $sub->whereIn('channel', ['vendor', 'reimbursement']))
                        ->orDoesntHave('expenseProfile');
                })
                ->with(['supplier', 'category', 'expenseProfile'])
                ->withSum('allocations as paid_sum', 'allocated_amount')
                ->orderByDesc('invoice_date')
                ->limit(20)
                ->get();

            $pettyExpenses = ApInvoice::query()
                ->where('is_expense', true)
                ->whereHas('expenseProfile', fn ($q) => $q->where('channel', 'petty_cash'))
                ->with(['supplier', 'category', 'expenseProfile.wallet'])
                ->withSum('allocations as paid_sum', 'allocated_amount')
                ->orderByDesc('invoice_date')
                ->limit(20)
                ->get();
        }

        return [
            'vendorExpenses' => $vendorExpenses,
            'pettyExpenses' => $pettyExpenses,
        ];
    }

    public function submitExpense(int $id, ExpenseWorkflowService $service): void
    {
        $invoice = ApInvoice::findOrFail($id);
        $service->submit($invoice, (int) auth()->id());
        session()->flash('status', __('Expense submitted.'));
    }

    public function approveManager(int $id, ExpenseWorkflowService $service): void
    {
        abort_unless($this->canManagerApprove(), 403);
        $invoice = ApInvoice::findOrFail($id);
        $service->approve($invoice, (int) auth()->id(), 'manager');
        session()->flash('status', __('Manager approval recorded.'));
    }

    public function approveFinance(int $id, ExpenseWorkflowService $service): void
    {
        abort_unless($this->canFinanceApprove(), 403);
        $invoice = ApInvoice::findOrFail($id);
        $service->approve($invoice, (int) auth()->id(), 'finance');
        session()->flash('status', __('Finance approval recorded.'));
    }

    public function rejectExpense(int $id, ExpenseWorkflowService $service): void
    {
        abort_unless($this->canManagerApprove() || $this->canFinanceApprove(), 403);
        $invoice = ApInvoice::findOrFail($id);
        $service->reject($invoice, (int) auth()->id(), 'Rejected from spend hub');
        session()->flash('status', __('Expense rejected.'));
    }

    public function postExpense(int $id, ExpenseWorkflowService $service): void
    {
        abort_unless($this->canFinanceApprove(), 403);
        $invoice = ApInvoice::findOrFail($id);
        $service->post($invoice, (int) auth()->id());
        session()->flash('status', __('Expense posted.'));
    }

    public function settleExpense(int $id, ExpenseWorkflowService $service): void
    {
        abort_unless($this->canFinanceApprove(), 403);
        $invoice = ApInvoice::findOrFail($id);
        $service->settle($invoice, (int) auth()->id());
        session()->flash('status', __('Expense settled.'));
    }

    private function canManagerApprove(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasAnyRole(['admin', 'manager']));
    }

    private function canFinanceApprove(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->hasRole('admin') || $user?->can('finance.access'));
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Spend') }}</h1>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('payables.invoices.create', ['is_expense' => 1])" wire:navigate>
                {{ __('New Expense Draft') }}
            </flux:button>
            <flux:button :href="route('payables.invoices.create', ['is_expense' => 1, 'channel' => 'petty_cash'])" wire:navigate variant="ghost">
                {{ __('New Petty Cash Expense') }}
            </flux:button>
            <flux:button :href="route('petty-cash.index')" wire:navigate variant="ghost">
                {{ __('Open Petty Cash Ops') }}
            </flux:button>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="flex gap-3">
        <button
            type="button"
            wire:click="$set('tab', 'vendor')"
            class="px-3 py-2 rounded-md text-sm font-semibold {{ $tab === 'vendor' ? 'bg-neutral-200 dark:bg-neutral-700' : 'bg-neutral-100 dark:bg-neutral-800' }}"
        >
            {{ __('Vendor/Reimbursement') }}
        </button>
        <button
            type="button"
            wire:click="$set('tab', 'petty')"
            class="px-3 py-2 rounded-md text-sm font-semibold {{ $tab === 'petty' ? 'bg-neutral-200 dark:bg-neutral-700' : 'bg-neutral-100 dark:bg-neutral-800' }}"
        >
            {{ __('Petty Cash Channel') }}
        </button>
    </div>

    @php
        $rows = $tab === 'petty' ? $pettyExpenses : $vendorExpenses;
    @endphp

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <div class="app-table-shell">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Invoice #') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Supplier/Wallet') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Category') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Approval') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Accounting') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse($rows as $inv)
                        @php
                            $profile = $inv->expenseProfile;
                            $approval = $profile?->approval_status ?? 'draft';
                            $canSettle = in_array($inv->status, ['posted', 'partially_paid'], true) && ! $profile?->settled_at;
                        @endphp
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $inv->invoice_date?->format('Y-m-d') }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $inv->invoice_number }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                @if(($profile?->channel ?? 'vendor') === 'petty_cash')
                                    {{ $profile?->wallet?->driver_name ?: $profile?->wallet?->driver_id ?: '—' }}
                                @else
                                    {{ $inv->supplier?->name ?? '—' }}
                                @endif
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->category?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-sm text-right text-neutral-900 dark:text-neutral-100">{{ number_format((float) $inv->total_amount, 2) }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $approval }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $inv->status }}</td>
                            <td class="px-3 py-2 text-sm">
                                <div class="flex flex-wrap justify-end gap-2">
                                    @if($approval === 'draft')
                                        <flux:button size="xs" type="button" wire:click="submitExpense({{ $inv->id }})">{{ __('Submit') }}</flux:button>
                                    @endif

                                    @if($approval === 'submitted' && auth()->user()?->hasAnyRole(['admin', 'manager']))
                                        <flux:button size="xs" type="button" wire:click="approveManager({{ $inv->id }})">{{ __('Manager Approve') }}</flux:button>
                                        <flux:button size="xs" type="button" wire:click="rejectExpense({{ $inv->id }})" variant="ghost">{{ __('Reject') }}</flux:button>
                                    @endif

                                    @if($approval === 'manager_approved' && (auth()->user()?->hasRole('admin') || auth()->user()?->can('finance.access')))
                                        <flux:button size="xs" type="button" wire:click="approveFinance({{ $inv->id }})">{{ __('Finance Approve') }}</flux:button>
                                        <flux:button size="xs" type="button" wire:click="rejectExpense({{ $inv->id }})" variant="ghost">{{ __('Reject') }}</flux:button>
                                    @endif

                                    @if($approval === 'approved' && $inv->status === 'draft' && (auth()->user()?->hasRole('admin') || auth()->user()?->can('finance.access')))
                                        <flux:button size="xs" type="button" wire:click="postExpense({{ $inv->id }})">{{ __('Post') }}</flux:button>
                                    @endif

                                    @if($canSettle && (auth()->user()?->hasRole('admin') || auth()->user()?->can('finance.access')))
                                        <flux:button size="xs" type="button" wire:click="settleExpense({{ $inv->id }})">{{ __('Settle') }}</flux:button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No expense records found.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

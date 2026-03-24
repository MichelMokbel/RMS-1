<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public function documentTypes(): array
    {
        return [
            [
                'label' => __('Vendor Bill'),
                'description' => __('Standard supplier bill matched to purchasing or entered directly for AP processing.'),
                'params' => ['document_type' => 'vendor_bill'],
            ],
            [
                'label' => __('Vendor Expense'),
                'description' => __('Operational expense routed through approvals and then posted or settled.'),
                'params' => ['document_type' => 'expense', 'expense_channel' => 'vendor'],
            ],
            [
                'label' => __('Petty Cash Expense'),
                'description' => __('Expense paid from a petty cash wallet and settled through the expense workflow.'),
                'params' => ['document_type' => 'expense', 'expense_channel' => 'petty_cash'],
            ],
            [
                'label' => __('Employee Reimbursement'),
                'description' => __('Employee claim that routes through approval and finance settlement.'),
                'params' => ['document_type' => 'reimbursement'],
            ],
            [
                'label' => __('Vendor Credit'),
                'description' => __('Credit note against a supplier account.'),
                'params' => ['document_type' => 'vendor_credit'],
            ],
            [
                'label' => __('Debit Memo'),
                'description' => __('Adjustment memo recorded against a supplier transaction.'),
                'params' => ['document_type' => 'debit_memo'],
            ],
            [
                'label' => __('Landed Cost Adjustment'),
                'description' => __('Adjustment document used to allocate additional cost into inventory receipts.'),
                'params' => ['document_type' => 'landed_cost_adjustment'],
            ],
            [
                'label' => __('Recurring Bill'),
                'description' => __('Template-backed bill entry for periodic payables.'),
                'params' => ['document_type' => 'recurring_bill'],
            ],
        ];
    }
}; ?>

<div class="w-full max-w-6xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('New AP Document') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Choose the document type first so the form can show the right workflow and fields.') }}</p>
        </div>
        <flux:button :href="route('payables.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->documentTypes() as $type)
            <a
                href="{{ route('payables.invoices.create', $type['params']) }}"
                wire:navigate
                class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-neutral-300 hover:shadow-md dark:border-neutral-700 dark:bg-neutral-900 dark:hover:border-neutral-600"
            >
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ $type['label'] }}</h2>
                        <span class="rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">{{ __('Create') }}</span>
                    </div>
                    <p class="text-sm leading-6 text-neutral-600 dark:text-neutral-300">{{ $type['description'] }}</p>
                </div>
            </a>
        @endforeach
    </div>
</div>

<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public function primaryDocumentTypes(): array
    {
        return [
            [
                'label' => __('Supplier Bill'),
                'description' => __('Use this when a supplier has invoiced you and the business still owes payment through Accounts Payable.'),
                'params' => ['document_type' => 'vendor_bill'],
            ],
            [
                'label' => __('Petty Cash Expense'),
                'description' => __('Use this when the expense was paid from a petty cash wallet instead of waiting for a supplier payment later.'),
                'params' => ['document_type' => 'expense', 'expense_channel' => 'petty_cash'],
            ],
            [
                'label' => __('Employee Reimbursement'),
                'description' => __('Use this when the company owes repayment to an employee for a business expense they already paid.'),
                'params' => ['document_type' => 'reimbursement'],
            ],
        ];
    }

    public function advancedDocumentTypes(): array
    {
        return [
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

<div class="w-full max-w-6xl mx-auto px-4 space-y-6" x-data="{ advancedOpen: false }" x-on:keydown.escape.window="advancedOpen = false">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('New AP Document') }}</h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Choose the business event first. The system will keep the underlying accounting document type and workflow unchanged.') }}</p>
        </div>
        <flux:button :href="route('payables.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    <section class="space-y-3">
        <div>
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Main Actions') }}</h2>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('These are the document choices most users need day to day.') }}</p>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->primaryDocumentTypes() as $type)
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
    </section>

    <div class="flex items-center justify-between rounded-xl border border-dashed border-neutral-300 bg-neutral-50/80 p-5 dark:border-neutral-700 dark:bg-neutral-900/40">
        <div class="space-y-1">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Advanced Documents') }}</h2>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('Open specialist accounting documents only when you need corrections, adjustments, or template-driven AP flows.') }}</p>
        </div>
        <flux:button type="button" variant="ghost" x-on:click="advancedOpen = true">{{ __('Open Advanced') }}</flux:button>
    </div>

    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
        {{ __('If a supplier sent you an invoice, use Supplier Bill. If money already left a petty cash wallet, use Petty Cash Expense. Employee Reimbursement is only for staff claims.') }}
    </div>

    <div
        x-cloak
        x-show="advancedOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-950/60 px-4 py-6"
        x-on:click.self="advancedOpen = false"
    >
        <div class="w-full max-w-4xl rounded-2xl border border-neutral-200 bg-white p-6 shadow-2xl dark:border-neutral-700 dark:bg-neutral-900">
            <div class="flex items-start justify-between gap-4">
                <div class="space-y-1">
                    <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Advanced Documents') }}</h2>
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('These options are for credits, adjustments, and recurring AP templates. They do not change the underlying backend document mappings.') }}</p>
                </div>
                <flux:button type="button" variant="ghost" x-on:click="advancedOpen = false">{{ __('Close') }}</flux:button>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-2">
                @foreach ($this->advancedDocumentTypes() as $type)
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
    </div>
</div>

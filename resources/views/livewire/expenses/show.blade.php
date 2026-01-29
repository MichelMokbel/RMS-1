<?php

use App\Models\Expense;
use App\Services\Expenses\ExpenseAttachmentService;
use App\Services\Expenses\ExpensePaymentService;
use App\Services\Expenses\ExpensePaymentVoidService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    public Expense $expense;
    public ?string $pay_date = null;
    public float $pay_amount = 0;
    public string $pay_method = 'cash';
    public ?string $pay_reference = null;
    public ?string $pay_notes = null;
    public $attachment;

    public function mount(Expense $expense): void
    {
        $this->expense = $expense->load(['supplier', 'category', 'payments', 'allPayments', 'attachments']);
        $this->pay_date = now()->toDateString();
        $this->pay_amount = (float) $this->expense->outstandingAmount();
    }

    public function addPayment(ExpensePaymentService $paymentService): void
    {
        if ($this->expense->payment_status === 'paid' || $this->expense->outstandingAmount() <= 0) {
            return;
        }

        $this->validate([
            'pay_date' => ['required', 'date'],
            'pay_amount' => ['required', 'numeric', 'min:0.01'],
            'pay_method' => ['required', 'in:cash,card,bank_transfer,cheque,other'],
            'pay_reference' => ['nullable', 'string', 'max:100'],
            'pay_notes' => ['nullable', 'string'],
        ]);

        $paymentService->addPayment($this->expense, [
            'payment_date' => $this->pay_date,
            'amount' => $this->pay_amount,
            'payment_method' => $this->pay_method,
            'reference' => $this->pay_reference,
            'notes' => $this->pay_notes,
        ], Illuminate\Support\Facades\Auth::id());

        $this->expense = $this->expense->fresh()->load(['supplier', 'category', 'payments', 'allPayments', 'attachments']);
        $this->pay_amount = (float) $this->expense->outstandingAmount();
        session()->flash('status', __('Payment added.'));
    }

    public function voidPayment(int $paymentId, ExpensePaymentVoidService $voidService): void
    {
        $payment = $this->expense->allPayments()->whereKey($paymentId)->firstOrFail();
        $voidService->void($payment, Illuminate\Support\Facades\Auth::id());

        $this->expense = $this->expense->fresh()->load(['supplier', 'category', 'payments', 'allPayments', 'attachments']);
        $this->pay_amount = (float) $this->expense->outstandingAmount();
        session()->flash('status', __('Payment voided.'));
    }

    public function deleteExpense(): void
    {
        $expense = $this->expense->fresh()->load(['payments', 'attachments']);

        if ($expense->payments()->exists() || $expense->attachments()->exists()) {
            $this->addError('delete', __('Cannot delete expense with payments or attachments.'));
            return;
        }

        DB::transaction(function () use ($expense) {
            $expense->delete();
        });

        session()->flash('status', __('Expense deleted.'));
        $this->redirectRoute('expenses.index', navigate: true);
    }

    public function uploadAttachment(ExpenseAttachmentService $attachmentService): void
    {
        $this->validate([
            'attachment' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.config('expenses.max_attachment_kb', 4096)],
        ]);

        $attachmentService->upload($this->expense, $this->attachment, Illuminate\Support\Facades\Auth::id());
        $this->expense->refresh()->load('attachments');
        $this->attachment = null;
        session()->flash('status', __('Attachment uploaded.'));
    }

    public function deleteAttachment(int $id, ExpenseAttachmentService $attachmentService): void
    {
        $att = $this->expense->attachments()->findOrFail($id);
        $attachmentService->delete($att);
        $this->expense->refresh()->load('attachments');
        session()->flash('status', __('Attachment removed.'));
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Expense Details') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('expenses.edit', $expense)" wire:navigate>{{ __('Edit') }}</flux:button>
            <flux:button :href="route('expenses.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
            <flux:button type="button" wire:click="deleteExpense" variant="ghost">{{ __('Delete') }}</flux:button>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif
    @error('delete')
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            {{ $message }}
        </div>
    @enderror

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase">{{ __('Date') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ $expense->expense_date?->format('Y-m-d') }}</p>
            </div>
            <div>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase">{{ __('Supplier') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ $expense->supplier->name ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase">{{ __('Category') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ $expense->category->name ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase">{{ __('Payment Method') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ ucfirst($expense->payment_method) }}</p>
            </div>
        </div>
        <div>
            <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase">{{ __('Description') }}</p>
            <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ $expense->description }}</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase">{{ __('Amount') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float)$expense->amount, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase">{{ __('Tax') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float)$expense->tax_amount, 2) }}</p>
            </div>
            <div>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase">{{ __('Total') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ number_format((float)$expense->total_amount, 2) }}</p>
            </div>
            <div>
                @php $paid = (float) $expense->payments()->sum('amount'); $out = max((float)$expense->total_amount - $paid, 0); @endphp
                <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase">{{ __('Status') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ $expense->payment_status }} — {{ __('Outstanding') }} {{ number_format($out, 2) }}</p>
            </div>
        </div>
        @if($expense->notes)
            <div>
                <p class="text-xs text-neutral-500 dark:text-neutral-400 uppercase">{{ __('Notes') }}</p>
                <p class="text-sm text-neutral-900 dark:text-neutral-100">{{ $expense->notes }}</p>
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Payments') }}</h3>
            </div>
            @php
                $paidTotal = (float) $expense->payments()->sum('amount');
                $outstanding = max((float)$expense->total_amount - $paidTotal, 0);
            @endphp

            @if($expense->payment_status === 'paid' || $outstanding <= 0)
                <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('This expense is fully paid.') }}</p>
            @else
                <div class="space-y-3">
                    <flux:input wire:model="pay_date" type="date" :label="__('Payment Date')" />
                    <flux:input wire:model="pay_amount" type="number" step="0.01" min="0.01" :label="__('Amount')" />
                    <div>
                        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Method') }}</label>
                        <select wire:model="pay_method" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="cheque">Cheque</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <flux:input wire:model="pay_reference" :label="__('Reference')" />
                    <flux:textarea wire:model="pay_notes" :label="__('Notes')" rows="2" />
                    <div class="flex justify-end">
                        <flux:button type="button" wire:click="addPayment" variant="primary">{{ __('Add Payment') }}</flux:button>
                    </div>
                </div>
            @endif
            <div class="border-t border-neutral-200 dark:border-neutral-700 pt-3 space-y-2">
                @forelse($expense->allPayments as $payment)
                    <div class="flex items-center justify-between text-sm text-neutral-800 dark:text-neutral-100">
                        <div class="flex flex-col">
                            <span>
                                {{ $payment->payment_date?->format('Y-m-d') }}
                                • {{ ucfirst($payment->payment_method) }}
                                @if($payment->voided_at)
                                    <span class="ml-2 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900 dark:text-amber-100">
                                        {{ __('Void') }}
                                    </span>
                                @endif
                            </span>
                            @if($payment->voided_at)
                                <span class="text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ __('Voided at') }} {{ $payment->voided_at?->format('Y-m-d H:i') }}
                                </span>
                            @endif
                        </div>
                        <div class="flex items-center gap-3">
                            <span>{{ number_format((float)$payment->amount, 2) }}</span>
                            @if(! $payment->voided_at)
                                <flux:button size="xs" variant="ghost" wire:click="voidPayment({{ $payment->id }})">
                                    {{ __('Void') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('No payments yet.') }}</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
            <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Attachments') }}</h3>
            <form wire:submit.prevent="uploadAttachment" class="space-y-2">
                <input type="file" wire:model="attachment" class="text-sm text-neutral-800 dark:text-neutral-50" />
                @error('attachment') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                <flux:button type="submit" variant="primary">{{ __('Upload') }}</flux:button>
            </form>
            <div class="space-y-2">
                @forelse($expense->attachments as $att)
                    <div class="flex items-center justify-between text-sm">
                        <a href="{{ asset('storage/'.$att->file_path) }}" target="_blank" class="text-primary-600 hover:underline">{{ $att->original_name }}</a>
                        <flux:button size="xs" wire:click="deleteAttachment({{ $att->id }})" variant="ghost">{{ __('Delete') }}</flux:button>
                    </div>
                @empty
                    <p class="text-sm text-neutral-600 dark:text-neutral-300">{{ __('No attachments') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>

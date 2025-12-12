<?php

use App\Models\ApInvoice;
use App\Models\Supplier;
use App\Services\AP\ApAllocationService;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $supplier_id = null;
    public ?string $payment_date = null;
    public float $amount = 0;
    public ?string $payment_method = 'bank_transfer';
    public ?string $reference = null;
    public ?string $notes = null;
    public array $allocations = [];

    public function mount(): void
    {
        $this->payment_date = now()->toDateString();
    }

    public function updatedSupplierId(): void
    {
        $this->loadInvoices();
    }

    public function loadInvoices(): void
    {
        if (! $this->supplier_id || ! Schema::hasTable('ap_invoices')) {
            $this->allocations = [];
            return;
        }
        $invoices = ApInvoice::where('supplier_id', $this->supplier_id)
            ->whereIn('status', ['posted', 'partially_paid'])
            ->get()
            ->map(function ($inv) {
                $paid = (float) $inv->allocations()->sum('allocated_amount');
                $outstanding = max((float) $inv->total_amount - $paid, 0);
                return [
                    'invoice_id' => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'due_date' => $inv->due_date?->format('Y-m-d'),
                    'outstanding' => $outstanding,
                    'allocated_amount' => 0,
                ];
            })
            ->filter(fn ($inv) => $inv['outstanding'] > 0)
            ->values()
            ->toArray();
        $this->allocations = $invoices;
    }

    public function save(ApAllocationService $allocationService): void
    {
        $payload = [
            'supplier_id' => $this->supplier_id,
            'payment_date' => $this->payment_date,
            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'allocations' => collect($this->allocations)
                ->filter(fn ($a) => (float) ($a['allocated_amount'] ?? 0) > 0)
                ->map(fn ($a) => ['invoice_id' => $a['invoice_id'], 'allocated_amount' => (float) $a['allocated_amount']])
                ->values()
                ->toArray(),
        ];

        if (empty($payload['allocations'])) {
            $this->addError('allocations', __('Allocate at least one invoice.'));
            return;
        }

        $data = $this->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'in:cash,bank_transfer,card,cheque,other'],
            'allocations' => ['sometimes', 'array'],
        ]);

        $payment = $allocationService->createPaymentWithAllocations($payload, Auth::id());

        session()->flash('status', __('Payment saved.'));
        $this->redirectRoute('payables.payments.show', $payment, navigate: true);
    }

    public function suppliers()
    {
        if (! Schema::hasTable('suppliers') || ! Schema::hasTable('ap_invoices')) {
            return collect();
        }

        $supplierIds = ApInvoice::select('supplier_id')
            ->groupBy('supplier_id')
            ->pluck('supplier_id');

        return Supplier::whereIn('id', $supplierIds)->orderBy('name')->get();
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('New Payment') }}</h1>
        <flux:button :href="route('payables.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Supplier') }}</label>
                    <select wire:model="supplier_id" wire:change="loadInvoices" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('Select supplier') }}</option>
                        @foreach($this->suppliers() as $sup)
                            <option value="{{ $sup->id }}">{{ $sup->name }}</option>
                        @endforeach
                    </select>
                    @error('supplier_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <flux:input wire:model="payment_date" type="date" :label="__('Payment Date')" />
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <flux:input wire:model="amount" type="number" step="0.01" min="0.01" :label="__('Amount')" />
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Method') }}</label>
                    <select wire:model="payment_method" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="cheque">Cheque</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <flux:input wire:model="reference" :label="__('Reference')" />
            </div>
            <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />
        </div>

        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Allocations') }}</h3>
                <p class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('Remaining') }}: {{ number_format((float)$amount - collect($allocations)->sum(fn($a)=> (float) ($a['allocated_amount'] ?? 0)), 2) }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                    <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Invoice') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Due') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Outstanding') }}</th>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Allocate') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        @forelse ($allocations as $idx => $alloc)
                            <tr>
                                <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $alloc['invoice_number'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $alloc['due_date'] }}</td>
                                <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ number_format((float)$alloc['outstanding'], 2) }}</td>
                                <td class="px-3 py-2 text-sm">
                                    <flux:input wire:model="allocations.{{ $idx }}.allocated_amount" type="number" step="0.01" min="0" />
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-3 text-sm text-neutral-600 dark:text-neutral-300 text-center">{{ __('No open invoices for supplier') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="flex justify-end">
            <flux:button type="submit" variant="primary">{{ __('Save Payment') }}</flux:button>
        </div>
    </form>
</div>

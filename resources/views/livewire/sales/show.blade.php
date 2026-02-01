<?php

use App\Models\Sale;
use App\Services\Sales\SaleService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public Sale $sale;
    public string $void_reason = '';

    public function mount(Sale $sale): void
    {
        $this->sale = $sale->load(['items', 'paymentAllocations.payment']);
    }

    public function voidSale(SaleService $service): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(403);
        }

        try {
            $this->sale = $service->void($this->sale, $user, $this->void_reason)->load(['items', 'paymentAllocations.payment']);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
            return;
        }

        session()->flash('status', __('Sale voided.'));
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0));
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Sale') }} {{ $sale->sale_number ?: ('#'.$sale->id) }}
            </h1>
            <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('Status') }}: {{ $sale->status }}</p>
        </div>
        <div class="flex items-center gap-2">
            <flux:button :href="route('sales.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
            @if ($sale->status === 'closed')
                <flux:button :href="route('sales.receipt', $sale)" target="_blank">{{ __('Receipt') }}</flux:button>
            @endif
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-4 text-sm">
            <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                <div class="text-neutral-500 dark:text-neutral-400">{{ __('Subtotal') }}</div>
                <div class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($sale->subtotal_cents) }}</div>
            </div>
            <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                <div class="text-neutral-500 dark:text-neutral-400">{{ __('Discount') }}</div>
                <div class="font-semibold text-neutral-900 dark:text-neutral-100">-{{ $this->formatMoney($sale->discount_total_cents) }}</div>
            </div>
            <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                <div class="text-neutral-500 dark:text-neutral-400">{{ __('Tax') }}</div>
                <div class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($sale->tax_total_cents) }}</div>
            </div>
            <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                <div class="text-neutral-500 dark:text-neutral-400">{{ __('Total') }}</div>
                <div class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($sale->total_cents) }}</div>
            </div>
        </div>

        <div class="border-t border-neutral-200 pt-4 dark:border-neutral-700">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Items') }}</h2>
            <div class="mt-3 space-y-2">
                @foreach ($sale->items as $row)
                    <div class="flex items-start justify-between gap-3 rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                        <div>
                            <div class="text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $row->name_snapshot }}</div>
                            <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                {{ __('Qty') }}: {{ $row->qty }} • {{ __('Unit') }}: {{ $this->formatMoney($row->unit_price_cents) }}
                            </div>
                        </div>
                        <div class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                            {{ $this->formatMoney($row->line_total_cents) }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="border-t border-neutral-200 pt-4 dark:border-neutral-700">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Payments') }}</h2>
            <div class="mt-3 space-y-2">
                @forelse ($sale->paymentAllocations as $alloc)
                    <div class="flex items-center justify-between rounded-md border border-neutral-200 p-3 text-sm dark:border-neutral-700">
                        <div class="text-neutral-700 dark:text-neutral-200">
                            {{ strtoupper($alloc->payment?->method ?? '—') }}
                            <span class="text-xs text-neutral-500 dark:text-neutral-400">• {{ $alloc->payment?->received_at?->format('Y-m-d H:i') }}</span>
                        </div>
                        <div class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($alloc->amount_cents) }}</div>
                    </div>
                @empty
                    <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ __('No payments recorded.') }}</p>
                @endforelse
            </div>
        </div>
    </div>

    @if ($sale->status === 'open' || $sale->status === 'draft')
        <div class="rounded-lg border border-rose-200 bg-white p-4 shadow-sm dark:border-rose-900 dark:bg-neutral-900 space-y-3">
            <h2 class="text-lg font-semibold text-rose-700 dark:text-rose-200">{{ __('Void Sale') }}</h2>
            <flux:input wire:model="void_reason" :label="__('Reason')" />
            @error('void_reason') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
            <div class="flex justify-end">
                <flux:button type="button" wire:click="voidSale" variant="danger">{{ __('Void') }}</flux:button>
            </div>
        </div>
    @endif
</div>


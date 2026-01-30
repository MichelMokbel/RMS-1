<?php

use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\PaymentTerm;
use App\Models\User;
use App\Services\AR\ArInvoiceService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 1;

    public ?int $customer_id = null;
    public string $customer_search = '';

    public string $invoice_date;
    public string $payment_type = 'credit';
    public ?int $payment_term_id = null;
    public ?int $sales_person_id = null;
    public ?string $lpo_reference = null;
    public string $invoice_discount_type = 'fixed';
    public string $invoice_discount_value = '0.000';

    public ?string $notes = null;

    public array $selected_items = [];
    public array $item_search = [];

    public function mount(): void
    {
        $this->branch_id = (int) config('inventory.default_branch_id', 1) ?: 1;
        $this->invoice_date = now()->toDateString();
        $this->sales_person_id = Auth::id();
        $this->payment_term_id = $this->defaultPaymentTermId('credit');
        $this->addItemRow();
    }

    public function with(): array
    {
        $customers = collect();
        if (Schema::hasTable('customers') && $this->customer_id === null && trim($this->customer_search) !== '') {
            $customers = Customer::query()
                ->search($this->customer_search)
                ->orderBy('name')
                ->limit(25)
                ->get();
        }

        $paymentTerms = collect();
        if (Schema::hasTable('payment_terms')) {
            $paymentTerms = PaymentTerm::query()
                ->where('is_active', 1)
                ->where('is_credit', 1)
                ->orderBy('days')
                ->get();
        }

        return [
            'customers' => $customers,
            'salesPeople' => User::query()->orderBy('username')->get(),
            'paymentTerms' => $paymentTerms,
            'branches' => Schema::hasTable('branches')
                ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get()
                : collect(),
        ];
    }

    public function selectCustomer(int $id): void
    {
        $this->customer_id = $id;
        $c = Customer::find($id);
        $this->customer_search = $c ? trim($c->name.' '.($c->phone ?? '')) : '';
        if ($this->payment_type === 'credit' && $c && $c->credit_terms_days) {
            $term = PaymentTerm::query()
                ->where('is_credit', 1)
                ->where('days', (int) $c->credit_terms_days)
                ->first();
            $this->payment_term_id = $term?->id;
        }
    }

    public function updatedPaymentType(): void
    {
        $this->payment_term_id = $this->defaultPaymentTermId($this->payment_type);
    }

    private function defaultPaymentTermId(string $type): ?int
    {
        if (! Schema::hasTable('payment_terms')) {
            return null;
        }
        if ($type === 'credit') {
            return PaymentTerm::query()
                ->where('is_active', 1)
                ->where('is_credit', 1)
                ->where('days', 30)
                ->value('id');
        }

        return PaymentTerm::query()
            ->where('is_active', 1)
            ->where('is_credit', 0)
            ->where('days', 0)
            ->value('id');
    }

    public function updatedItemSearch($value, $name): void
    {
        $parts = explode('.', (string) $name);
        $index = (int) end($parts);
        if (! array_key_exists($index, $this->selected_items)) {
            return;
        }

        $this->selected_items[$index]['menu_item_id'] = null;
        $this->selected_items[$index]['unit_price'] = null;
    }

    public function addItemRow(): void
    {
        $this->selected_items[] = [
            'menu_item_id' => null,
            'quantity' => 1,
            'unit' => '',
            'unit_price' => null,
            'discount_amount' => 0,
            'line_notes' => null,
            'sort_order' => count($this->selected_items),
        ];
        $this->item_search[] = '';
    }

    public function removeItemRow(int $index): void
    {
        unset($this->selected_items[$index], $this->item_search[$index]);
        $this->selected_items = array_values($this->selected_items);
        $this->item_search = array_values($this->item_search);
        if (count($this->selected_items) === 0) {
            $this->addItemRow();
        }
    }

    public function selectMenuItemPayload(int $index, int $menuItemId, string $label, ?float $price = null): void
    {
        if (! array_key_exists($index, $this->selected_items)) {
            return;
        }

        $this->selected_items[$index]['menu_item_id'] = $menuItemId;
        $this->selected_items[$index]['quantity'] = $this->selected_items[$index]['quantity'] ?? 1;
        $this->selected_items[$index]['unit_price'] = $price !== null ? (float) $price : 0.0;
        $this->selected_items[$index]['discount_amount'] = $this->selected_items[$index]['discount_amount'] ?? 0;
        $this->selected_items[$index]['sort_order'] = $this->selected_items[$index]['sort_order'] ?? $index;
        $this->item_search[$index] = $label;
    }

    public function clearMenuItemSelection(int $index): void
    {
        if (! array_key_exists($index, $this->selected_items)) {
            return;
        }

        $this->selected_items[$index]['menu_item_id'] = null;
        $this->selected_items[$index]['unit_price'] = null;
        $this->item_search[$index] = '';
    }

    public function saveDraft(ArInvoiceService $service): void
    {
        $this->persist($service, issue: false);
    }

    public function saveAndIssue(ArInvoiceService $service): void
    {
        $this->persist($service, issue: true);
    }

    private function persist(ArInvoiceService $service, bool $issue): void
    {
        $this->resetErrorBag();

        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }

        if (! $this->customer_id) {
            $this->addError('customer_id', __('Customer is required.'));
            return;
        }
        if ($this->payment_type === 'credit' && ! $this->payment_term_id) {
            $this->addError('payment_term_id', __('Payment term is required for credit.'));
            return;
        }

        $items = [];
        foreach ($this->selected_items as $idx => $row) {
            $menuItemId = (int) ($row['menu_item_id'] ?? 0);
            if ($menuItemId <= 0) {
                continue;
            }
            $menuItem = MenuItem::find($menuItemId);
            $desc = $menuItem ? trim(($menuItem->code ?? '').' '.$menuItem->name) : __('Item');
            $qty = (string) ($row['quantity'] ?? '1.000');
            $unitStr = (string) ($row['unit_price'] ?? '0.000');
            $discountStr = (string) ($row['discount_amount'] ?? '0.000');
            $unit = (string) ($row['unit'] ?? '');
            $lineNotes = $row['line_notes'] ?? null;

            try {
                $unitCents = MinorUnits::parse($unitStr);
            } catch (\InvalidArgumentException $e) {
                $this->addError("selected_items.$idx.unit_price", __('Invalid unit price.'));
                return;
            }

            $qtyMilli = MinorUnits::parseQtyMilli($qty);
            if ($qtyMilli <= 0) {
                $this->addError("selected_items.$idx.quantity", __('Quantity must be positive.'));
                return;
            }

            try {
                $discountCents = MinorUnits::parse($discountStr);
            } catch (\InvalidArgumentException $e) {
                $this->addError("selected_items.$idx.discount_amount", __('Invalid discount.'));
                return;
            }

            $lineSubtotal = MinorUnits::mulQty($unitCents, $qtyMilli);
            $lineTotal = max(0, $lineSubtotal - $discountCents);

            $items[] = [
                'description' => $desc,
                'qty' => $qty,
                'unit' => $unit,
                'unit_price_cents' => $unitCents,
                'discount_cents' => $discountCents,
                'tax_cents' => 0,
                'line_total_cents' => $lineTotal,
                'sellable_type' => MenuItem::class,
                'sellable_id' => $menuItem?->id,
                'name_snapshot' => $menuItem?->name,
                'sku_snapshot' => $menuItem?->code,
                'line_notes' => $lineNotes,
            ];
        }

        if (count($items) === 0) {
            $this->addError('selected_items', __('Add at least one line.'));
            return;
        }

        try {
            $invoiceDiscountValue = $this->invoice_discount_type === 'percent'
                ? $this->parsePercentToBps($this->invoice_discount_value)
                : MinorUnits::parse($this->invoice_discount_value);
            $invoice = $service->createDraft(
                branchId: $this->branch_id,
                customerId: $this->customer_id,
                items: $items,
                actorId: $userId,
                currency: 'KWD',
                sourceSaleId: null,
                type: 'invoice',
                issueDate: $this->invoice_date,
                paymentType: $this->payment_type,
                paymentTermId: $this->payment_term_id,
                paymentTermDays: 0,
                salesPersonId: $this->sales_person_id,
                lpoReference: $this->lpo_reference,
                invoiceDiscountType: $this->invoice_discount_type,
                invoiceDiscountValue: $invoiceDiscountValue,
            );
            if ($this->notes) {
                $invoice->update(['notes' => $this->notes, 'updated_by' => $userId]);
            }
            if ($issue) {
                $invoice = $service->issue($invoice, $userId);
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
            return;
        }

        session()->flash('status', $issue ? __('Invoice issued.') : __('Invoice saved.'));
        $this->redirectRoute('invoices.show', $invoice, navigate: true);
    }

    public function formatMoney(?int $cents): string
    {
        $cents = (int) ($cents ?? 0);
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        $whole = intdiv($cents, 1000);
        $frac = $cents % 1000;
        return $sign.$whole.'.'.str_pad((string) $frac, 3, '0', STR_PAD_LEFT);
    }

    private function parsePercentToBps(string $percent): int
    {
        $percent = trim($percent);
        if ($percent === '') {
            return 0;
        }
        $negative = str_starts_with($percent, '-');
        if ($negative) {
            $percent = ltrim($percent, '-');
        }
        $percent = str_replace(',', '', $percent);
        if (! preg_match('/^\\d+(\\.\\d+)?$/', $percent)) {
            return 0;
        }
        [$whole, $frac] = array_pad(explode('.', $percent, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $frac = substr(str_pad($frac, 2, '0', STR_PAD_RIGHT), 0, 2);
        $bps = ((int) $whole) * 100 + (int) $frac;
        return $negative ? -$bps : $bps;
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Create Invoice') }}</h1>
        <flux:button :href="route('invoices.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
                @if ($branches->count())
                    <select wire:model.live="branch_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                @else
                    <flux:input wire:model.live="branch_id" type="number" :label="__('Branch ID')" />
                @endif
            </div>

            <div class="md:col-span-2 relative">
                <flux:input wire:model.live.debounce.300ms="customer_search" :label="__('Customer')" placeholder="{{ __('Search by name/phone/code') }}" />
                @error('customer_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                @if($customer_id === null && trim($customer_search) !== '')
                    <div class="absolute z-10 mt-1 w-full overflow-hidden rounded-md border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <div class="max-h-64 overflow-auto">
                            @forelse ($customers as $c)
                                <button type="button" class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80" wire:click="selectCustomer({{ $c->id }})">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium">{{ $c->name }}</span>
                                        @if($c->customer_code)
                                            <span class="text-xs text-neutral-500 dark:text-neutral-400">{{ $c->customer_code }}</span>
                                        @endif
                                    </div>
                                    @if($c->phone)
                                        <div class="text-xs text-neutral-500 dark:text-neutral-400">{{ $c->phone }}</div>
                                    @endif
                                </button>
                            @empty
                                <div class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No customers found.') }}</div>
                            @endforelse
                        </div>
                    </div>
                @endif
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Invoice Date') }}</label>
                <input type="date" wire:model.live="invoice_date" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Payment Type') }}</label>
                <select wire:model.live="payment_type" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="credit">{{ __('Credit') }}</option>
                    <option value="cash">{{ __('Cash') }}</option>
                    <option value="card">{{ __('Card') }}</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Payment Term') }}</label>
                @if ($payment_type === 'credit')
                    <select wire:model.live="payment_term_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="">{{ __('Select') }}</option>
                        @foreach ($paymentTerms as $term)
                            <option value="{{ $term->id }}">{{ $term->name }}</option>
                        @endforeach
                    </select>
                    @error('payment_term_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
                @else
                    <div class="mt-1 rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm text-neutral-700">{{ __('Immediate') }}</div>
                @endif
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Sales Person') }}</label>
                <select wire:model.live="sales_person_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('Select') }}</option>
                    @foreach ($salesPeople as $person)
                        <option value="{{ $person->id }}">{{ $person->username }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <flux:input wire:model.live="lpo_reference" :label="__('LPO Reference')" />
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="md:col-span-2">
                <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Invoice Discount') }}</label>
                <div class="mt-1 flex items-center gap-2">
                    <select wire:model.live="invoice_discount_type" class="rounded-md border border-neutral-200 bg-white px-2 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="fixed">{{ __('Fixed') }}</option>
                        <option value="percent">{{ __('%') }}</option>
                    </select>
                    <flux:input wire:model.live="invoice_discount_value" />
                </div>
            </div>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Line Items') }}</h2>
            <flux:button type="button" wire:click="addItemRow">{{ __('Add line') }}</flux:button>
        </div>

        @error('selected_items') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

        <div class="overflow-x-auto">
            @php
                $subtotal = 0;
                $discountTotal = 0;
                foreach ($selected_items as $row) {
                    $qty = (float) ($row['quantity'] ?? 0);
                    $price = (float) ($row['unit_price'] ?? 0);
                    $discount = (float) ($row['discount_amount'] ?? 0);
                    $lineSubtotal = $qty * $price;
                    $subtotal += $lineSubtotal;
                    $discountTotal += $discount;
                }
                $invoiceDiscount = $invoice_discount_type === 'percent'
                    ? max(0, (($subtotal - $discountTotal) * ((float) $invoice_discount_value / 100)))
                    : max(0, (float) $invoice_discount_value);
                $total = max(0, ($subtotal - $discountTotal - $invoiceDiscount));
            @endphp
            <table class="w-full min-w-[980px] table-fixed divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100 w-72">{{ __('Item') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100 w-20">{{ __('Qty') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100 w-20">{{ __('Unit') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100 w-24">{{ __('Price') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100 w-24">{{ __('Discount') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100 w-24">{{ __('Total') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100 w-56">{{ __('Notes') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100 w-16">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @foreach ($selected_items as $idx => $row)
                        @php
                            $qty = (float) ($row['quantity'] ?? 0);
                            $price = (float) ($row['unit_price'] ?? 0);
                            $discount = (float) ($row['discount_amount'] ?? 0);
                            $lineTotal = max(0, ($qty * $price) - $discount);
                        @endphp
                        <tr class="align-top">
                            <td class="px-3 py-3 text-sm">
                                <div
                                    class="relative"
                                    wire:ignore
                                    x-data="menuItemLookup({
                                        index: {{ $idx }},
                                        initial: @js($item_search[$idx] ?? ''),
                                        selectedId: @js($row['menu_item_id'] ?? null),
                                        searchUrl: '{{ route('orders.menu-items.search') }}',
                                        branchId: @entangle('branch_id')
                                    })"
                                    x-on:keydown.escape.stop="close()"
                                    x-on:click.outside="close()"
                                >
                                    <input
                                        type="text"
                                        class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                                        x-model="query"
                                        x-on:input.debounce.200ms="onInput()"
                                        x-on:focus="onInput(true)"
                                        placeholder="{{ __('Search item') }}"
                                    />
                                    <template x-if="open">
                                        <div
                                            x-ref="panel"
                                            x-bind:style="panelStyle"
                                            class="mb-1 overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900"
                                        >
                                            <div class="max-h-60 overflow-auto">
                                                <template x-for="item in results" :key="item.id">
                                                    <button
                                                        type="button"
                                                        class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80"
                                                        x-on:click="choose(item)"
                                                    >
                                                        <div class="flex items-center justify-between gap-2">
                                                            <span class="font-medium" x-text="item.name"></span>
                                                            <span class="text-xs text-neutral-500 dark:text-neutral-400" x-show="item.code" x-text="item.code"></span>
                                                        </div>
                                                        <div class="text-xs text-neutral-500 dark:text-neutral-400" x-show="item.price_formatted" x-text="item.price_formatted"></div>
                                                    </button>
                                                </template>
                                                <div x-show="loading" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                                                    {{ __('Searching...') }}
                                                </div>
                                                <div x-show="!loading && hasSearched && results.length === 0" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                                                    {{ __('No items found.') }}
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-sm">
                                <flux:input wire:model.live.debounce.300ms="selected_items.{{ $idx }}.quantity" type="number" step="0.001" class="w-20" />
                            </td>
                            <td class="px-3 py-3 text-sm">
                                <flux:input wire:model.live.debounce.300ms="selected_items.{{ $idx }}.unit" class="w-20" />
                            </td>
                            <td class="px-3 py-3 text-sm">
                                <flux:input wire:model.live.debounce.300ms="selected_items.{{ $idx }}.unit_price" type="number" step="0.001" class="w-24" />
                            </td>
                            <td class="px-3 py-3 text-sm">
                                <flux:input wire:model.live.debounce.300ms="selected_items.{{ $idx }}.discount_amount" type="number" step="0.001" class="w-24" />
                            </td>
                            <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ number_format($lineTotal, 3, '.', '') }}
                            </td>
                            <td class="px-3 py-3 text-sm">
                                <flux:input wire:model.live.debounce.300ms="selected_items.{{ $idx }}.line_notes" />
                            </td>
                            <td class="px-3 py-3 text-sm text-right">
                                <flux:button type="button" wire:click="removeItemRow({{ $idx }})" variant="ghost">{{ __('Remove') }}</flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex justify-end">
            <div class="w-full max-w-xs space-y-2 text-sm">
                <div class="flex items-center justify-between text-neutral-600 dark:text-neutral-300">
                    <span>{{ __('Subtotal') }}</span>
                    <span class="font-medium text-neutral-900 dark:text-neutral-100">{{ number_format($subtotal, 3, '.', '') }}</span>
                </div>
                <div class="flex items-center justify-between text-neutral-600 dark:text-neutral-300">
                    <span>{{ __('Line Discounts') }}</span>
                    <span class="font-medium text-neutral-900 dark:text-neutral-100">{{ number_format($discountTotal, 3, '.', '') }}</span>
                </div>
                <div class="flex items-center justify-between text-neutral-600 dark:text-neutral-300">
                    <span>{{ __('Invoice Discount') }}</span>
                    <span class="font-medium text-neutral-900 dark:text-neutral-100">{{ number_format($invoiceDiscount, 3, '.', '') }}</span>
                </div>
                <div class="flex items-center justify-between text-neutral-700 dark:text-neutral-200 font-semibold">
                    <span>{{ __('Total') }}</span>
                    <span>{{ number_format($total, 3, '.', '') }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="flex justify-end gap-3">
        <flux:button type="button" wire:click="saveDraft">{{ __('Save Draft') }}</flux:button>
        <flux:button type="button" wire:click="saveAndIssue" variant="primary">{{ __('Save & Issue') }}</flux:button>
    </div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('menuItemLookup', ({ index, initial, selectedId, searchUrl, branchId }) => ({
        index,
        query: initial || '',
        selectedId: selectedId || null,
        selectedLabel: initial || '',
        searchUrl,
        branchId,
        results: [],
        loading: false,
        open: false,
        hasSearched: false,
        panelStyle: '',
        controller: null,
        onInput(force = false) {
            if (this.selectedId !== null && this.query !== this.selectedLabel) {
                this.selectedId = null;
                this.selectedLabel = '';
                this.$wire.clearMenuItemSelection(this.index);
            }

            const term = this.query.trim();
            if (!force && term.length < 2) {
                this.open = false;
                this.results = [];
                this.hasSearched = false;
                return;
            }
            if (term.length < 2) {
                this.open = false;
                this.results = [];
                this.hasSearched = false;
                return;
            }

            this.fetchResults(term);
        },
        fetchResults(term) {
            this.loading = true;
            this.hasSearched = true;
            this.open = true;
            if (this.controller) {
                this.controller.abort();
            }
            this.controller = new AbortController();
            const params = new URLSearchParams({ q: term });
            if (this.branchId) {
                params.append('branch_id', this.branchId);
            }
            fetch(this.searchUrl + '?' + params.toString(), {
                headers: { 'Accept': 'application/json' },
                signal: this.controller.signal,
                credentials: 'same-origin',
            })
                .then((response) => response.ok ? response.json() : [])
                .then((data) => {
                    this.results = Array.isArray(data) ? data : [];
                    this.loading = false;
                    this.$nextTick(() => this.positionDropdown());
                })
                .catch((error) => {
                    if (error.name === 'AbortError') {
                        return;
                    }
                    this.loading = false;
                    this.results = [];
                });
        },
        choose(item) {
            const label = item.label || item.name || '';
            this.query = label;
            this.selectedLabel = label;
            this.selectedId = item.id;
            this.open = false;
            this.results = [];
            this.loading = false;
            this.$wire.selectMenuItemPayload(this.index, item.id, label, item.price);
        },
        close() {
            this.open = false;
        },
        positionDropdown() {
            if (!this.$refs.panel) return;
            const inputRect = this.$el.getBoundingClientRect();
            const panel = this.$refs.panel;
            const panelRect = panel.getBoundingClientRect();
            const top = inputRect.bottom + window.scrollY;
            const left = inputRect.left + window.scrollX;
            const width = inputRect.width;
            this.panelStyle = `position: fixed; top: ${top}px; left: ${left}px; width: ${width}px;`;
        },
    }));
});
</script>


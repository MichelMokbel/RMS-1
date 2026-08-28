<article wire:key="bulk-invoice-{{ $importInvoice->id }}" @class([
    'rounded-lg border bg-white p-5 dark:bg-neutral-900',
    'border-red-300 dark:border-red-800' => $groupErrors !== [],
    'border-neutral-200 dark:border-neutral-700' => $groupErrors === [],
    'opacity-60' => $importInvoice->excluded,
])>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="font-medium">{{ $importInvoice->entry_id }}</p>
            <p class="text-xs text-neutral-500">
                {{ optional($importInvoice->business_date)->format('d M Y') ?: ($header['business_date'] ?? '—') }} ·
                {{ number_format($entry['total'], 2) }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <span class="rounded-full bg-neutral-100 px-2 py-1 text-xs dark:bg-neutral-800">
                {{ $importInvoice->excluded ? __('Excluded') : Str::headline($importInvoice->status->value ?? (string) $importInvoice->status) }}
            </span>
            @if($editable)
                <flux:button type="button" wire:click="toggleInvoice({{ $importInvoice->id }}, {{ $importInvoice->excluded ? 'false' : 'true' }})" size="sm" variant="ghost">
                    {{ $importInvoice->excluded ? __('Restore Invoice') : __('Exclude Invoice') }}
                </flux:button>
            @endif
        </div>
    </div>

    @if($editable && ! $importInvoice->excluded)
        <form wire:submit="saveInvoice({{ $importInvoice->id }})" class="mt-4 space-y-3">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <flux:input wire:model="invoiceForms.{{ $importInvoice->id }}.business_date" type="date" :label="__('Business Date')" />
                <div><label class="mb-1 block text-sm font-medium">{{ __('Supplier') }}</label><select wire:model="invoiceForms.{{ $importInvoice->id }}.supplier_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800"><option value="">{{ __('Choose supplier') }}</option>@foreach($allSuppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></div>
                <flux:input wire:model="invoiceForms.{{ $importInvoice->id }}.reference_number" :label="__('Reference')" />
                <flux:input wire:model="invoiceForms.{{ $importInvoice->id }}.due_date" type="date" :label="__('Due Date')" />
                <flux:input wire:model="invoiceForms.{{ $importInvoice->id }}.category" :label="__('Category')" list="expense-category-names" />
                @unless($usesBank)<div><label class="mb-1 block text-sm font-medium">{{ __('Wallet') }}</label><select wire:model="invoiceForms.{{ $importInvoice->id }}.wallet_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800"><option value="">{{ __('Choose wallet') }}</option>@foreach($allWallets as $wallet)<option value="{{ $wallet->id }}">{{ $wallet->driver_name ?: __('Custodian :id', ['id' => $wallet->driver_id]) }}</option>@endforeach</select></div>@endunless
                <div><label class="mb-1 block text-sm font-medium">{{ __('Paid') }}</label><select wire:model="invoiceForms.{{ $importInvoice->id }}.paid" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800"><option value="0">{{ __('Unpaid') }}</option><option value="1">{{ __('Paid') }}</option></select></div>
                <flux:input wire:model="invoiceForms.{{ $importInvoice->id }}.notes" :label="__('Notes')" />
            </div>
            <div class="flex justify-end"><flux:button type="submit" size="sm" variant="primary">{{ __('Save Invoice') }}</flux:button></div>
        </form>

        <div class="mt-5 space-y-2 border-t border-neutral-200 pt-4 dark:border-neutral-700">
            <div class="flex items-center justify-between"><h3 class="text-sm font-medium">{{ __('Line Items') }}</h3><flux:button type="button" wire:click="addRow({{ $importInvoice->id }})" size="sm" variant="ghost" icon="plus">{{ __('Add Line') }}</flux:button></div>
            @foreach($importInvoice->rows as $line)
                <form wire:key="bulk-row-{{ $line->id }}" wire:submit="saveRow({{ $line->id }})" @class(['grid gap-2 rounded-md bg-neutral-50 p-3 md:grid-cols-[minmax(0,1fr)_8rem_10rem_auto] dark:bg-neutral-800', 'opacity-50' => $line->excluded])>
                    <flux:input wire:model="rowForms.{{ $line->id }}.description" :label="__('Description')" :disabled="$line->excluded" />
                    <flux:input wire:model="rowForms.{{ $line->id }}.quantity" type="number" step="0.001" min="0.001" :label="__('Quantity')" :disabled="$line->excluded" />
                    <flux:input wire:model="rowForms.{{ $line->id }}.unit_price" type="number" step="0.0001" min="0" :label="__('Unit Price')" :disabled="$line->excluded" />
                    <div class="flex items-end gap-1">
                        @unless($line->excluded)<flux:button type="submit" size="sm">{{ __('Save') }}</flux:button>@endunless
                        <flux:button type="button" wire:click="toggleRow({{ $line->id }}, {{ $line->excluded ? 'false' : 'true' }})" size="sm" variant="ghost">{{ $line->excluded ? __('Restore') : __('Remove') }}</flux:button>
                    </div>
                </form>
            @endforeach
        </div>
    @else
        <dl class="mt-3 grid grid-cols-2 gap-2 text-sm md:grid-cols-4">
            <div><dt class="text-xs text-neutral-500">{{ __('Supplier') }}</dt><dd>{{ $entry['supplier'] }}</dd></div>
            <div><dt class="text-xs text-neutral-500">{{ __('Category') }}</dt><dd>{{ $entry['category'] }}</dd></div>
            <div><dt class="text-xs text-neutral-500">{{ $usesBank ? __('Bank Account') : __('Wallet') }}</dt><dd>{{ $entry['payment_source'] }}</dd></div>
            <div><dt class="text-xs text-neutral-500">{{ __('Settlement') }}</dt><dd>{{ ($header['paid'] ?? false) ? __('Paid') : __('Pending') }}</dd></div>
        </dl>
    @endif

    @if(filled($header['settlement_warning'] ?? null))
        <p class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">{{ $header['settlement_warning'] }}</p>
    @endif
    @if($groupErrors !== [])
        <ul class="mt-3 list-disc space-y-1 pl-5 text-xs text-red-700 dark:text-red-300">@foreach($groupErrors as $message)<li>{{ is_array($message) ? implode(' ', $message) : $message }}</li>@endforeach</ul>
    @endif
</article>

<?php

use App\Services\Finance\FinanceSettingsService;
use App\Models\LedgerAccount;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public ?string $lock_date = null;
    public string $po_quantity_tolerance_percent = '0.000';
    public string $po_price_tolerance_percent = '0.000';
    public ?int $purchase_price_variance_account_id = null;

    public function mount(FinanceSettingsService $service): void
    {
        $this->authorizeManager();
        $settings = $service->getSettings();
        $this->lock_date = $settings['lock_date'];
        $this->po_quantity_tolerance_percent = number_format((float) ($settings['po_quantity_tolerance_percent'] ?? 0), 3, '.', '');
        $this->po_price_tolerance_percent = number_format((float) ($settings['po_price_tolerance_percent'] ?? 0), 3, '.', '');
        $this->purchase_price_variance_account_id = $settings['purchase_price_variance_account_id'];
    }

    public function save(FinanceSettingsService $service): void
    {
        $this->authorizeManager();

        $data = $this->validate([
            'lock_date' => ['nullable', 'date'],
            'po_quantity_tolerance_percent' => ['required', 'numeric', 'min:0'],
            'po_price_tolerance_percent' => ['required', 'numeric', 'min:0'],
            'purchase_price_variance_account_id' => ['nullable', 'integer', 'exists:ledger_accounts,id'],
        ]);

        $settings = $service->saveSettings($data, Auth::id());
        $this->lock_date = $settings['lock_date'];

        session()->flash('status', __('Finance lock date updated.'));
    }

    private function authorizeManager(): void
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (! $user || (! $user->hasRole('admin') && ! $user->hasRole('manager') && ! $user->can('finance.access'))) {
            abort(403);
        }
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Finance')" :subheading="__('Set the lock date to close periods and block back-dated postings')">
        @if(session('status'))
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                {{ session('status') }}
            </div>
        @endif

        <div class="mt-6 space-y-4">
            <flux:input wire:model="lock_date" type="date" :label="__('Lock date')" />
            <div class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="po_quantity_tolerance_percent" type="number" step="0.001" :label="__('PO quantity tolerance %')" />
                <flux:input wire:model="po_price_tolerance_percent" type="number" step="0.001" :label="__('PO price tolerance %')" />
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-neutral-800 dark:text-neutral-200">{{ __('Purchase price variance account') }}</label>
                <select wire:model="purchase_price_variance_account_id" class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('Fallback to Inventory Adjustments') }}</option>
                    @foreach (LedgerAccount::query()->where('allow_direct_posting', true)->orderBy('code')->get() as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="save" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>

            <div class="text-xs text-neutral-500 dark:text-neutral-400">
                <p>{{ __('Tip: set the lock date to the end of the last closed period (e.g. month-end).') }}</p>
                <p>{{ __('PO tolerances control when PO-linked AP bills can be posted without a finance override.') }}</p>
            </div>
        </div>
    </x-settings.layout>
</section>

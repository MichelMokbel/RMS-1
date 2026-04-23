<?php

use App\Models\ApChequeClearance;
use App\Services\AP\ApChequeClearanceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ApChequeClearance $clearance;

    public string $void_reason = '';
    public bool $confirm_void = false;

    public function mount(ApChequeClearance $clearance): void
    {
        $this->clearance = $clearance->load(['apPayment.supplier', 'bankAccount']);
    }

    public function voidClearance(ApChequeClearanceService $service): void
    {
        if ($this->clearance->voided_at) {
            return;
        }

        try {
            $service->void($this->clearance, Auth::id(), $this->void_reason ?: null);

            $this->clearance = $this->clearance->fresh(['apPayment.supplier', 'bankAccount']);
            $this->confirm_void = false;
            $this->void_reason  = '';

            session()->flash('status', __('Clearance #:id has been voided.', ['id' => $this->clearance->id]));
        } catch (ValidationException $e) {
            session()->flash('error', collect($e->errors())->flatten()->first());
        } catch (\Throwable $e) {
            session()->flash('error', __('Failed to void clearance: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function formatAmount(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2);
    }
}; ?>

<div class="w-full max-w-5xl mx-auto px-4 space-y-6">

    {{-- Back link + header --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                {{ __('Clearance') }} #{{ $clearance->id }}
                @if ($clearance->voided_at)
                    <span class="ml-2 inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-sm font-semibold text-red-800 dark:bg-red-900/40 dark:text-red-300">
                        {{ __('Voided') }}
                    </span>
                @else
                    <span class="ml-2 inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-sm font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                        {{ __('Active') }}
                    </span>
                @endif
            </h1>
            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                {{ __('AP Cheque Clearance') }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            @if (! $clearance->voided_at)
                <flux:button
                    type="button"
                    wire:click="$set('confirm_void', true)"
                    variant="ghost">
                    {{ __('Void') }}
                </flux:button>
            @endif
            <flux:button
                :href="route('accounting.ap-cheque-clearance')"
                wire:navigate
                variant="ghost">
                {{ __('Back') }}
            </flux:button>
        </div>
    </div>

    {{-- Flash messages --}}
    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
            {{ session('error') }}
        </div>
    @endif

    {{-- Void confirmation panel --}}
    @if ($confirm_void && ! $clearance->voided_at)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
            <p class="mb-3 text-sm font-medium text-amber-900 dark:text-amber-200">
                {{ __('Are you sure you want to void Clearance #:id? This action cannot be undone.', ['id' => $clearance->id]) }}
            </p>
            <div class="mb-3">
                <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-200">
                    {{ __('Void Reason') }} <span class="text-neutral-500 dark:text-neutral-400">({{ __('optional') }})</span>
                </label>
                <textarea
                    wire:model="void_reason"
                    rows="2"
                    placeholder="{{ __('Enter reason for voiding…') }}"
                    class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 placeholder-neutral-400 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 dark:placeholder-neutral-500">
                </textarea>
            </div>
            <div class="flex gap-2">
                <flux:button
                    type="button"
                    variant="primary"
                    wire:click="voidClearance"
                    wire:loading.attr="disabled">
                    {{ __('Confirm Void') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="ghost"
                    wire:click="$set('confirm_void', false)">
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Info cards --}}
    @php $currency = config('pos.currency', 'QAR'); @endphp
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">

        {{-- Left card: payment & supplier info --}}
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Cheque Details') }}</h3>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                <span class="font-medium">{{ __('Supplier') }}:</span>
                {{ $clearance->apPayment?->supplier?->name ?? '—' }}
            </p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                <span class="font-medium">{{ __('Cheque Reference') }}:</span>
                {{ $clearance->apPayment?->reference ?? '—' }}
            </p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                <span class="font-medium">{{ __('Payment Date') }}:</span>
                {{ $clearance->apPayment?->payment_date instanceof \Carbon\Carbon
                    ? $clearance->apPayment->payment_date->format('Y-m-d')
                    : ($clearance->apPayment?->payment_date ?? '—') }}
            </p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                <span class="font-medium">{{ __('Payment Method') }}:</span>
                {{ strtoupper($clearance->apPayment?->payment_method ?? '—') }}
            </p>
        </div>

        {{-- Right card: clearance info --}}
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-2">
            <h3 class="text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Clearance Details') }}</h3>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                <span class="font-medium">{{ __('Clearance Date') }}:</span>
                {{ $clearance->clearance_date instanceof \Carbon\Carbon
                    ? $clearance->clearance_date->format('Y-m-d')
                    : ($clearance->clearance_date ?? '—') }}
            </p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                <span class="font-medium">{{ __('Bank Account') }}:</span>
                {{ $clearance->bankAccount?->name ?? '—' }}
            </p>
            <p class="text-sm text-neutral-700 dark:text-neutral-200">
                <span class="font-medium">{{ __('Amount') }}:</span>
                {{ $currency }} {{ $this->formatAmount($clearance->amount) }}
            </p>
            @if ($clearance->reference)
                <p class="text-sm text-neutral-700 dark:text-neutral-200">
                    <span class="font-medium">{{ __('Reference') }}:</span>
                    {{ $clearance->reference }}
                </p>
            @endif
            @if ($clearance->notes)
                <p class="text-sm text-neutral-700 dark:text-neutral-200">
                    <span class="font-medium">{{ __('Notes') }}:</span>
                    {{ $clearance->notes }}
                </p>
            @endif
        </div>
    </div>

    {{-- Voided info --}}
    @if ($clearance->voided_at)
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950/20 space-y-1">
            <p class="text-sm font-semibold text-red-800 dark:text-red-300">{{ __('This clearance has been voided.') }}</p>
            <p class="text-sm text-red-700 dark:text-red-400">
                <span class="font-medium">{{ __('Voided at') }}:</span>
                {{ $clearance->voided_at instanceof \Carbon\Carbon
                    ? $clearance->voided_at->format('Y-m-d H:i')
                    : $clearance->voided_at }}
            </p>
            @if ($clearance->void_reason)
                <p class="text-sm text-red-700 dark:text-red-400">
                    <span class="font-medium">{{ __('Reason') }}:</span>
                    {{ $clearance->void_reason }}
                </p>
            @endif
        </div>
    @endif

    {{-- Accounting note --}}
    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <h3 class="mb-1 text-sm font-semibold text-neutral-800 dark:text-neutral-200">{{ __('Accounting Entry') }}</h3>
        <p class="text-sm text-neutral-600 dark:text-neutral-400">
            {{ __('Clearance posted:') }}
            <span class="font-medium text-neutral-800 dark:text-neutral-200">DR Issued Cheques Clearing</span>
            /
            <span class="font-medium text-neutral-800 dark:text-neutral-200">CR Bank</span>
        </p>
    </div>

</div>

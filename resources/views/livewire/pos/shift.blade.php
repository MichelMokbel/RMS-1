<?php

use App\Models\PosShift;
use App\Services\POS\PosShiftService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 1;

    public string $opening_cash = '0.00';
    public string $counted_cash = '0.00';
    public ?string $notes = null;

    public function mount(): void
    {
        $this->branch_id = (int) config('inventory.default_branch_id', 1) ?: 1;
        $this->opening_cash = $this->moneyZero();
        $this->counted_cash = $this->moneyZero();
    }

    public function with(PosShiftService $service): array
    {
        $userId = Auth::id();
        $active = $userId ? $service->activeShiftFor($this->branch_id, $userId) : null;

        return [
            'activeShift' => $active,
            'branches' => Schema::hasTable('branches')
                ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get()
                : collect(),
        ];
    }

    public function openShift(PosShiftService $service): void
    {
        $this->resetErrorBag();

        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }

        try {
            $opening = MinorUnits::parsePos($this->opening_cash);
        } catch (\InvalidArgumentException $e) {
            $this->addError('opening_cash', __('Invalid opening cash amount.'));
            return;
        }

        try {
            $service->open($this->branch_id, $userId, $opening, $userId);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
            return;
        }

        session()->flash('status', __('Shift opened.'));
    }

    public function closeShift(PosShiftService $service): void
    {
        $this->resetErrorBag();

        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }

        $shift = $service->activeShiftFor($this->branch_id, $userId);
        if (! $shift) {
            $this->addError('shift', __('No active shift.'));
            return;
        }

        try {
            $counted = MinorUnits::parsePos($this->counted_cash);
        } catch (\InvalidArgumentException $e) {
            $this->addError('counted_cash', __('Invalid counted cash amount.'));
            return;
        }

        try {
            $service->close($shift, $counted, $this->notes, $userId);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
            return;
        }

        session()->flash('status', __('Shift closed.'));
    }

    public function formatMoney(?int $cents): string
    {
        return \App\Support\Money\MinorUnits::format((int) ($cents ?? 0), null, true);
    }

    public function moneyScaleDigits(): int
    {
        return MinorUnits::scaleDigits(MinorUnits::posScale());
    }

    public function moneyZero(): string
    {
        $digits = $this->moneyScaleDigits();
        if ($digits <= 0) {
            return '0';
        }

        return '0.'.str_repeat('0', $digits);
    }
}; ?>

<div class="w-full max-w-4xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('POS Shift') }}</h1>
        <flux:button :href="route('pos.index')" wire:navigate variant="ghost">{{ __('Back to POS') }}</flux:button>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
                @if ($branches->count())
                    <select wire:model.live="branch_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                @else
                    <flux:input wire:model.live="branch_id" type="number" :label="__('Branch ID')" />
                @endif
            </div>
        </div>

        @error('shift') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
    </div>

    @if (! $activeShift)
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Open Shift') }}</h2>
            <flux:input wire:model="opening_cash" :label="__('Opening Cash')" />
            @error('opening_cash') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

            <div class="flex justify-end">
                <flux:button type="button" wire:click="openShift" variant="primary">{{ __('Open Shift') }}</flux:button>
            </div>
        </div>
    @else
        <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
            <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Active Shift') }}</h2>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-3 text-sm">
                <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                    <div class="text-neutral-500 dark:text-neutral-400">{{ __('Opened At') }}</div>
                    <div class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $activeShift->opened_at?->format('Y-m-d H:i') }}</div>
                </div>
                <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                    <div class="text-neutral-500 dark:text-neutral-400">{{ __('Opening Cash') }}</div>
                    <div class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $this->formatMoney($activeShift->opening_cash_cents) }}</div>
                </div>
                <div class="rounded-md border border-neutral-200 p-3 dark:border-neutral-700">
                    <div class="text-neutral-500 dark:text-neutral-400">{{ __('Expected Cash (so far)') }}</div>
                    <div class="font-semibold text-neutral-900 dark:text-neutral-100">
                        {{ $activeShift->expected_cash_cents !== null ? $this->formatMoney($activeShift->expected_cash_cents) : '—' }}
                    </div>
                </div>
            </div>

            <div class="border-t border-neutral-200 pt-4 dark:border-neutral-700 space-y-3">
                <h3 class="text-base font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Close Shift') }}</h3>
                <flux:input wire:model="counted_cash" :label="__('Counted Cash')" />
                @error('counted_cash') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />
                <div class="flex justify-end">
                    <flux:button type="button" wire:click="closeShift" variant="danger">{{ __('Close Shift') }}</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>


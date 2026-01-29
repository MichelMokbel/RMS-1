<?php

use App\Services\Finance\FinanceSettingsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Livewire\Volt\Component;

new class extends Component {
    public ?string $lock_date = null;

    public function mount(FinanceSettingsService $service): void
    {
        $this->authorizeManager();
        $this->lock_date = $service->getLockDate() ?? Config::get('finance.lock_date');
    }

    public function save(FinanceSettingsService $service): void
    {
        $this->authorizeManager();

        $data = $this->validate([
            'lock_date' => ['nullable', 'date'],
        ]);

        $service->setLockDate($data['lock_date'] ?? null, Auth::id());
        $this->lock_date = $service->getLockDate();

        session()->flash('status', __('Finance lock date updated.'));
    }

    private function authorizeManager(): void
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        if (! $user || (! $user->hasRole('admin') && ! $user->hasRole('manager'))) {
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

            <div class="flex justify-end gap-2">
                <flux:button type="button" wire:click="save" variant="primary">
                    {{ __('Save') }}
                </flux:button>
            </div>

            <div class="text-xs text-neutral-500 dark:text-neutral-400">
                <p>{{ __('Tip: set the lock date to the end of the last closed period (e.g. month-end).') }}</p>
            </div>
        </div>
    </x-settings.layout>
</section>

<?php

use App\Models\PaymentTerm;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public ?int $editing_id = null;
    public string $name = '';
    public int $days = 0;
    public bool $is_credit = true;
    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorizeManager();
    }

    public function with(): array
    {
        $this->authorizeManager();

        return [
            'terms' => PaymentTerm::query()
                ->orderBy('is_active', 'desc')
                ->orderBy('days')
                ->orderBy('name')
                ->get(),
        ];
    }

    public function startCreate(): void
    {
        $this->editing_id = null;
        $this->name = '';
        $this->days = 0;
        $this->is_credit = true;
        $this->is_active = true;
    }

    public function edit(int $id): void
    {
        $this->authorizeManager();

        $term = PaymentTerm::find($id);
        if (! $term) {
            return;
        }
        $this->editing_id = $term->id;
        $this->name = $term->name;
        $this->days = (int) $term->days;
        $this->is_credit = (bool) $term->is_credit;
        $this->is_active = (bool) $term->is_active;
    }

    public function save(): void
    {
        $this->authorizeManager();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'days' => ['required', 'integer', 'min:0'],
            'is_credit' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($this->editing_id) {
            $term = PaymentTerm::find($this->editing_id);
            if (! $term) {
                throw ValidationException::withMessages(['term' => __('Payment term not found.')]);
            }
            $term->update($data);
            session()->flash('status', __('Payment term updated.'));
        } else {
            PaymentTerm::create($data);
            session()->flash('status', __('Payment term created.'));
        }

        $this->startCreate();
    }

    public function toggleActive(int $id): void
    {
        $this->authorizeManager();

        $term = PaymentTerm::find($id);
        if (! $term) {
            return;
        }
        $term->update(['is_active' => ! $term->is_active]);
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

    <x-settings.layout :heading="__('Payment Terms')" :subheading="__('Create and manage credit terms used on AR invoices')">
        @if(session('status'))
            <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                {{ session('status') }}
            </div>
        @endif

        <div class="mt-6 space-y-4">
            <div class="rounded-md border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
                <div class="text-sm font-semibold text-neutral-800 dark:text-neutral-100">
                    {{ $editing_id ? __('Edit Term') : __('New Term') }}
                </div>
                <flux:input wire:model="name" :label="__('Name')" placeholder="{{ __('Credit - 30 days from delivery') }}" />
                <flux:input wire:model="days" type="number" min="0" :label="__('Days from delivery')" />
                <div class="flex items-center gap-4 text-sm">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model="is_credit" />
                        <span>{{ __('Credit Term') }}</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model="is_active" />
                        <span>{{ __('Active') }}</span>
                    </label>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="ghost" wire:click="startCreate">{{ __('Clear') }}</flux:button>
                    <flux:button type="button" variant="primary" wire:click="save">{{ __('Save') }}</flux:button>
                </div>
            </div>

            <div class="rounded-md border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
                <div class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse ($terms as $term)
                        <div class="flex items-center justify-between px-4 py-3 text-sm">
                            <div>
                                <div class="font-medium text-neutral-800 dark:text-neutral-100">{{ $term->name }}</div>
                                <div class="text-xs text-neutral-500 dark:text-neutral-400">
                                    {{ $term->is_credit ? __('Credit') : __('Non-credit') }} • {{ $term->days }} {{ __('days') }}
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:button size="xs" variant="ghost" wire:click="edit({{ $term->id }})">{{ __('Edit') }}</flux:button>
                                <flux:button size="xs" variant="outline" wire:click="toggleActive({{ $term->id }})">
                                    {{ $term->is_active ? __('Disable') : __('Enable') }}
                                </flux:button>
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-6 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No payment terms yet.') }}</div>
                    @endforelse
                </div>
            </div>
        </div>
    </x-settings.layout>
</section>

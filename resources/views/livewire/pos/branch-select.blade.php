<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 0;

    public function with(): array
    {
        return [
            'branches' => Schema::hasTable('branches')
                ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get()
                : collect(),
        ];
    }

    public function selectBranch(): void
    {
        if ($this->branch_id <= 0) {
            $this->addError('branch_id', __('Select a branch.'));
            return;
        }
        session(['pos_branch_id' => $this->branch_id]);
        $this->redirectRoute('pos.index', navigate: true);
    }
}; ?>

<div class="w-full max-w-xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Select POS Branch') }}</h1>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
        <div>
            <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
            <select wire:model="branch_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                <option value="0">{{ __('Select a branch') }}</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                @endforeach
            </select>
            @error('branch_id') <p class="text-xs text-rose-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="flex justify-end">
            <flux:button type="button" wire:click="selectBranch" variant="primary">{{ __('Continue to POS') }}</flux:button>
        </div>
    </div>
</div>

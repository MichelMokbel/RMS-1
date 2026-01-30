@props([
    'name' => 'branch_id',
    'branches' => null,
])

@php
    $branches = $branches ?? (\Illuminate\Support\Facades\Schema::hasTable('branches') ? \Illuminate\Support\Facades\DB::table('branches')->where('is_active', 1)->orderBy('name')->get() : collect());
@endphp

<div class="min-w-[180px]">
    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
    @if ($branches->count())
        <select wire:model.live="{{ $name }}" name="{{ $name }}" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
            <option value="">{{ __('All') }}</option>
            @foreach ($branches as $b)
                <option value="{{ $b->id }}">{{ $b->name }}</option>
            @endforeach
        </select>
    @else
        <input type="number" wire:model.live="{{ $name }}" name="{{ $name }}" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" placeholder="{{ __('Branch ID') }}" />
    @endif
</div>

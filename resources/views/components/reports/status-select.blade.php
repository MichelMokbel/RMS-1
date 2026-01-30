@props([
    'name' => 'status',
    'options' => [], // [['value' => 'all', 'label' => 'All'], ...]
])

<div class="min-w-[160px]">
    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
    <select wire:model.live="{{ $name }}" name="{{ $name }}" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
        @foreach ($options as $opt)
            <option value="{{ $opt['value'] }}">{{ $opt['label'] ?? $opt['value'] }}</option>
        @endforeach
    </select>
</div>

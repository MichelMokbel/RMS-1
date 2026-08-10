@php
    $spanOptions = [
        12 => __('Full'),
        9 => __('3/4'),
        8 => __('2/3'),
        6 => __('Half'),
        4 => __('1/3'),
        3 => __('Quarter'),
    ];
@endphp

<div class="mb-3 grid gap-3 rounded-md bg-neutral-50 p-3 dark:bg-neutral-800 sm:grid-cols-2 xl:grid-cols-4">
    <div>
        <label class="mb-1 block text-xs font-medium text-neutral-600 dark:text-neutral-300">{{ __('Width') }}</label>
        <select wire:model.live.number="{{ $modelPrefix }}.settings.column_span" class="w-full rounded border border-neutral-300 bg-white px-2 py-1.5 text-xs dark:border-neutral-600 dark:bg-neutral-900">
            @foreach($spanOptions as $span => $label)
                <option value="{{ $span }}">{{ $label }} · {{ $span }}/12</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-neutral-600 dark:text-neutral-300">{{ __('Horizontal alignment') }}</label>
        <select wire:model.live="{{ $modelPrefix }}.settings.horizontal_alignment" class="w-full rounded border border-neutral-300 bg-white px-2 py-1.5 text-xs dark:border-neutral-600 dark:bg-neutral-900">
            <option value="start">{{ __('Start') }}</option>
            <option value="center">{{ __('Center') }}</option>
            <option value="end">{{ __('End') }}</option>
            <option value="stretch">{{ __('Stretch') }}</option>
        </select>
    </div>
    <div>
        <label class="mb-1 block text-xs font-medium text-neutral-600 dark:text-neutral-300">{{ __('Text direction') }}</label>
        <select wire:model.live="{{ $modelPrefix }}.direction" class="w-full rounded border border-neutral-300 bg-white px-2 py-1.5 text-xs dark:border-neutral-600 dark:bg-neutral-900">
            <option value="auto">{{ __('Automatic') }}</option>
            <option value="ltr">LTR</option>
            <option value="rtl">RTL</option>
        </select>
    </div>
    <div class="flex items-end pb-1">
        <flux:checkbox wire:model.live="{{ $modelPrefix }}.settings.new_row" :label="__('Start on a new row')" />
    </div>
</div>

@if($block['type'] === 'company_header')
    <div class="mb-3 grid gap-3 rounded-md border border-neutral-200 p-3 dark:border-neutral-700 sm:grid-cols-3">
        <div class="flex items-end pb-1">
            <flux:checkbox wire:model.live="{{ $modelPrefix }}.settings.show_logo" :label="__('Show company logo')" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-neutral-600 dark:text-neutral-300">{{ __('Logo position') }}</label>
            <select wire:model.live="{{ $modelPrefix }}.settings.logo_position" class="w-full rounded border border-neutral-300 bg-white px-2 py-1.5 text-xs dark:border-neutral-600 dark:bg-neutral-900">
                <option value="start">{{ __('Start') }}</option>
                <option value="end">{{ __('End') }}</option>
                <option value="top">{{ __('Above company details') }}</option>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-neutral-600 dark:text-neutral-300">{{ __('Header content alignment') }}</label>
            <select wire:model.live="{{ $modelPrefix }}.settings.alignment" class="w-full rounded border border-neutral-300 bg-white px-2 py-1.5 text-xs dark:border-neutral-600 dark:bg-neutral-900">
                <option value="start">{{ __('Start') }}</option>
                <option value="center">{{ __('Center') }}</option>
                <option value="end">{{ __('End') }}</option>
            </select>
        </div>
    </div>
@endif

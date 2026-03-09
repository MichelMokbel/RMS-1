@props([
    'fromName' => 'date_from',
    'toName' => 'date_to',
])

<div class="flex flex-wrap items-end gap-3">
    <div class="w-40">
        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('From') }}</label>
        <input type="date" wire:model.live="{{ $fromName }}" name="{{ $fromName }}" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
    </div>
    <div class="w-40">
        <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('To') }}</label>
        <input type="date" wire:model.live="{{ $toName }}" name="{{ $toName }}" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50" />
    </div>
</div>

@props(['plain' => false])
<div x-data="{}" class="relative {{ $plain ? '' : $attributes->get('class') }}" data-whole-unit-input
     x-on:keydown.up.prevent="window.rmsStepNumber($el, 1)"
     x-on:keydown.down.prevent="window.rmsStepNumber($el, -1)">
    @if ($plain)
        <input {{ $attributes->except(['type', 'step'])->class('whole-unit-number') }} type="number" step="any" />
    @else
        <flux:input {{ $attributes->except(['type', 'step', 'class']) }} type="number" step="any" class:input="whole-unit-number" />
    @endif
    <span class="whole-unit-controls no-print" aria-label="{{ __('Adjust value') }}">
        <button type="button" tabindex="-1" aria-label="{{ __('Increase by 1') }}"
                x-on:click="window.rmsStepNumber($el.closest('[data-whole-unit-input]'), 1)">▴</button>
        <button type="button" tabindex="-1" aria-label="{{ __('Decrease by 1') }}"
                x-on:click="window.rmsStepNumber($el.closest('[data-whole-unit-input]'), -1)">▾</button>
    </span>
</div>

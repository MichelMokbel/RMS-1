<?php

use Illuminate\Support\Facades\Blade;

it('renders decimal-compatible controls preserving purchase order bindings and limits', function () {
    view()->share('errors', new \Illuminate\Support\ViewErrorBag);
    $html = Blade::render('<x-number-input wire:model.live="lines.0.quantity" min="0.001" type="number" label="Quantity" />');
    expect($html)->toContain('step="any"', 'min="0.001"', 'wire:model.live="lines.0.quantity"', 'Increase by 1', 'Decrease by 1');
});

it('preserves disabled plain numeric fields and their existing styling', function () {
    view()->share('errors', new \Illuminate\Support\ViewErrorBag);
    $html = Blade::render('<x-number-input :plain="true" disabled type="number" class="w-24" value="1.375" />');
    expect($html)->toContain('disabled', 'w-24', 'value="1.375"', 'step="any"');
});

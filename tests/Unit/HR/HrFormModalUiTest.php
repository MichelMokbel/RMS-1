<?php

/** @return array{source:string,page:string} */
function hrModalView(string $relativePath): array
{
    $source = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);
    $page = preg_replace('/<flux:modal(?:\s|>)[^>]*>.*?<\/flux:modal>/s', '', $source);

    return ['source' => $source, 'page' => $page];
}

it('keeps HR settings inputs and create forms inside Flux modals', function (): void {
    ['source' => $source, 'page' => $page] = hrModalView('resources/views/livewire/hr/settings/index.blade.php');

    expect($source)
        ->toMatch('/<flux:modal name="select-hr-settings-company".*?<form wire:submit="selectCompany".*?<\/form>.*?<\/flux:modal>/s')
        ->toMatch('/<flux:modal name="create-hr-leave-policy".*?<form wire:submit="createPolicy".*?<\/form>.*?<\/flux:modal>/s')
        ->toContain('<flux:modal.trigger name="select-hr-settings-company">')
        ->toContain('<flux:modal.trigger name="create-hr-leave-policy">')
        ->toContain("\$this->dispatch('modal-close', name: 'select-hr-settings-company')")
        ->toContain("\$this->dispatch('modal-close', name: 'create-hr-leave-policy')")
        ->and(substr_count($source, '<flux:modal.close>'))->toBeGreaterThanOrEqual(2)
        ->and($page)->not->toContain('<form')->not->toContain('<flux:input')->not->toContain('<flux:select')->not->toContain('<flux:checkbox');
});

it('keeps HR import staging inputs inside a Flux modal and sends commit to review', function (): void {
    ['source' => $source, 'page' => $page] = hrModalView('resources/views/livewire/hr/imports/index.blade.php');

    expect($source)
        ->toMatch('/<flux:modal name="stage-hr-import".*?<form wire:submit="stage".*?<\/form>.*?<\/flux:modal>/s')
        ->toContain('<flux:modal.trigger name="stage-hr-import">')
        ->toContain("\$this->dispatch('modal-close', name: 'stage-hr-import')")
        ->toContain('<flux:modal.close>')
        ->and($page)->not->toContain('<form')->not->toContain('<flux:input')->not->toContain('<flux:select')->not->toContain('<flux:checkbox')
        ->and($page)->toContain("route('hr.imports.show', ['batch' => \$batch->id])")
        ->and($page)->not->toContain('wire:click="commit(');
});

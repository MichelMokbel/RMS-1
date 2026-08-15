<?php

it('defaults HR imports to the consolidated full employee history workbook', function (): void {
    $root = dirname(__DIR__, 3);
    $source = file_get_contents($root.'/resources/views/livewire/hr/imports/index.blade.php');
    $routes = file_get_contents($root.'/routes/hr.php');

    expect($source)
        ->toContain("public string \$import_type = 'full_history'")
        ->toContain("'import_type' => ['required', 'in:full_history,employees,assignments,documents,leave,payroll']")
        ->toContain("\$data['import_type'] === 'full_history' ? null : \$this->archive")
        ->toContain("route('hr.imports.template', ['type' => \$import_type, 'company_id' => \$company_id])")
        ->toContain('<flux:select wire:model.live="company_id"')
        ->toContain("__('Full employee history')")
        ->toContain("__('Recommended')")
        ->toContain('One workbook covers employee profiles, assignments, compensation, leave balances and history, plus payroll and component history.')
        ->toContain('Documents are excluded and should be uploaded to each employee profile after the history import.')
        ->toContain("__('Advanced: import one data type')")
        ->and($routes)->toContain("['full_history', 'employees', 'assignments', 'documents', 'leave', 'payroll']");
});

it('keeps the full-history archive excluded and the staging form modal-only', function (): void {
    $source = file_get_contents(dirname(__DIR__, 3).'/resources/views/livewire/hr/imports/index.blade.php');

    expect($source)
        ->toContain("'prohibited_if:import_type,full_history'")
        ->toContain("@if(\$import_type !== 'full_history')")
        ->toMatch('/<flux:modal name="stage-hr-import".*?<form wire:submit="stage".*?<\/form>.*?<\/flux:modal>/s')
        ->toContain("\$this->dispatch('modal-close', name: 'stage-hr-import')")
        ->toContain('<flux:modal.close>');
});

it('redirects every staged workbook to a dedicated review page before commit', function (): void {
    $root = dirname(__DIR__, 3);
    $source = file_get_contents($root.'/resources/views/livewire/hr/imports/index.blade.php');
    $review = file_get_contents($root.'/resources/views/livewire/hr/imports/show.blade.php');
    $routes = file_get_contents($root.'/routes/hr.php');

    expect($source)
        ->toContain("\$sheetStats = \$stats['sheets'] ?? []")
        ->toContain("\$this->redirectRoute('hr.imports.show', ['batch' => \$batch->id], navigate: true)")
        ->toContain("route('hr.imports.show', ['batch' => \$batch->id])")
        ->toContain("__('Upload Excel workbook')")
        ->toContain("__('Review staged rows')")
        ->not->toContain('wire:click="commit(')
        ->and($routes)->toContain("Volt::route('imports/{batch}', 'hr.imports.show')")
        ->and($review)
        ->toContain("__('Staged row review')")
        ->toContain("\$payload['_sheet']")
        ->toContain("\$payload['_sheet_row']")
        ->toContain("__('Validation errors')")
        ->toContain('<flux:modal name="commit-hr-import"')
        ->toContain('wire:click="commit"')
        ->toContain("abort_unless(\$this->status(\$batch) === 'ready', 422)")
        ->toContain("whereIn('company_id', \$this->companyIds())");
});

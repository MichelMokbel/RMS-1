<?php

it('does not expose generated HR identifiers as form fields', function (): void {
    $root = dirname(__DIR__, 3);
    $employeeCreate = file_get_contents($root.'/resources/views/livewire/hr/employees/create.blade.php');
    $payroll = file_get_contents($root.'/resources/views/livewire/hr/payroll/index.blade.php');

    expect($employeeCreate)
        ->not->toContain('public string $employee_number')
        ->not->toContain('wire:model="employee_number"')
        ->not->toContain("'employee_number' =>")
        ->and($payroll)
        ->not->toContain('wire:model="run_number"')
        ->not->toContain('wire:model="batch_number"');
});

it('uses an explicit legacy identifier only in the employee import template', function (): void {
    $root = dirname(__DIR__, 3);
    $controller = file_get_contents($root.'/app/Http/Controllers/HR/HrImportTemplateController.php');

    expect($controller)
        ->toContain("'employees' => ['legacy_employee_number'")
        ->not->toContain("'employees' => ['employee_number'")
        ->not->toContain('run_number')
        ->not->toContain('batch_number');
});

it('keeps external legal and payment references editable', function (): void {
    $root = dirname(__DIR__, 3);
    $employeeEdit = file_get_contents($root.'/resources/views/livewire/hr/employees/edit.blade.php');
    $employeeShow = file_get_contents($root.'/resources/views/livewire/hr/employees/show.blade.php');
    $payroll = file_get_contents($root.'/resources/views/livewire/hr/payroll/index.blade.php');

    expect($employeeEdit)->toContain('wire:model="qid_number"')->toContain('wire:model="passport_number"')
        ->and($employeeShow)->not->toContain('wire:model="document_number"')
        ->and($employeeShow)->toContain('The HR document number is assigned automatically.')
        ->and($payroll)->toContain('wire:model="payment_reference"');
});

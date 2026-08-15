<?php

function hrEmployeeView(string $name): string
{
    return file_get_contents(dirname(__DIR__, 3)."/resources/views/livewire/hr/employees/{$name}.blade.php");
}

/** @return array{forms:int,modal_forms:int,cancels:int} */
function hrEmployeeModalStructure(string $view): array
{
    preg_match_all('/<\/?flux:modal(?:\s|>)|<\/?form(?:\s|>)/', $view, $matches, PREG_OFFSET_CAPTURE);
    $modalDepth = 0;
    $forms = 0;
    $modalForms = 0;

    foreach ($matches[0] as [$tag]) {
        if (str_starts_with($tag, '<flux:modal')) {
            $modalDepth++;
        } elseif ($tag === '</flux:modal>') {
            $modalDepth--;
        } elseif (str_starts_with($tag, '<form')) {
            $forms++;
            if ($modalDepth > 0) {
                $modalForms++;
            }
        }
    }

    return [
        'forms' => $forms,
        'modal_forms' => $modalForms,
        'cancels' => substr_count($view, '<flux:modal.close>'),
    ];
}

it('creates employees directly from a directory modal', function (): void {
    $view = hrEmployeeView('index');

    expect($view)
        ->toContain('<flux:modal.trigger name="directory-add-employee-modal">')
        ->toContain('<flux:modal name="directory-add-employee-modal"')
        ->toContain('<form wire:submit="createEmployee"')
        ->toContain("dispatch('modal-close', name: 'directory-add-employee-modal')")
        ->not->toContain(':href="route(\'hr.employees.create\')"');
});

it('keeps the legacy create page as a modal launcher', function (): void {
    $view = hrEmployeeView('create');

    expect($view)
        ->toContain('<flux:modal.trigger name="add-employee-modal">')
        ->toContain('<flux:modal name="add-employee-modal"')
        ->toContain('<form wire:submit="save"')
        ->toContain("dispatch('modal-close', name: 'add-employee-modal')");
});

it('separates employee editing actions into three closing modals', function (): void {
    $view = hrEmployeeView('edit');

    foreach ([
        'edit-employee-profile-modal' => 'saveProfile',
        'record-employee-assignment-modal' => 'transfer',
        'change-employee-status-modal' => 'transition',
    ] as $modal => $action) {
        expect($view)
            ->toContain("<flux:modal.trigger name=\"{$modal}\">")
            ->toContain("<flux:modal name=\"{$modal}\"")
            ->toContain("<form wire:submit=\"{$action}\"")
            ->toContain("dispatch('modal-close', name: '{$modal}')");
    }
});

it('uses separate closing modals for compensation and document upload', function (): void {
    $view = hrEmployeeView('show');

    foreach ([
        'create-employee-compensation-modal' => 'createCompensation',
        'upload-employee-document-modal' => 'uploadDocument',
    ] as $modal => $action) {
        expect($view)
            ->toContain("<flux:modal.trigger name=\"{$modal}\">")
            ->toContain("<flux:modal name=\"{$modal}\"")
            ->toContain("<form wire:submit=\"{$action}\"")
            ->toContain("dispatch('modal-close', name: '{$modal}')");
    }
});

it('nests every employee workflow form in a modal with a cancel control', function (): void {
    foreach (['index' => 1, 'create' => 1, 'edit' => 3, 'show' => 2] as $name => $expectedForms) {
        $structure = hrEmployeeModalStructure(hrEmployeeView($name));

        expect($structure['forms'])->toBe($expectedForms)
            ->and($structure['modal_forms'])->toBe($expectedForms)
            ->and($structure['cancels'])->toBe($expectedForms);
    }
});

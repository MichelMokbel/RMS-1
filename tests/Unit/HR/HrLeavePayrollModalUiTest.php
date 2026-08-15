<?php

/** Return the body of a named Flux modal from an HR Volt view. */
function hrWorkspaceModal(string $source, string $name): string
{
    $pattern = '/<flux:modal name="'.preg_quote($name, '/').'".*?<\/flux:modal>/s';

    expect($source)->toMatch($pattern);
    preg_match($pattern, $source, $matches);

    return $matches[0];
}

it('keeps leave entry and decision inputs in named Flux modals', function (): void {
    $source = file_get_contents(dirname(__DIR__, 3).'/resources/views/livewire/hr/leave/index.blade.php');

    $create = hrWorkspaceModal($source, 'hr-leave-create');
    $adjust = hrWorkspaceModal($source, 'hr-leave-balance-adjust');
    $reject = hrWorkspaceModal($source, 'hr-leave-reject');

    expect($source)
        ->toContain('<flux:modal.trigger name="hr-leave-create">')
        ->toContain('<flux:modal.trigger name="hr-leave-balance-adjust">')
        ->toContain('wire:click="openReject(')
        ->toContain("\$this->modal('hr-leave-create')->close()")
        ->toContain("\$this->modal('hr-leave-balance-adjust')->close()")
        ->toContain("\$this->modal('hr-leave-reject')->close()")
        ->and($create)->toContain('wire:submit="create"')->toContain('wire:model="employee_id"')
        ->and($adjust)->toContain('wire:submit="adjustBalance"')->toContain('wire:model="adjust_days"')
        ->and($reject)->toContain('wire:submit="reject"')->toContain('wire:model="decision_note"')
        ->and(substr_count($source, '<flux:modal.close>'))->toBe(3);
});

it('keeps payroll input actions in named Flux modals', function (): void {
    $source = file_get_contents(dirname(__DIR__, 3).'/resources/views/livewire/hr/payroll/index.blade.php');

    $create = hrWorkspaceModal($source, 'hr-payroll-create');
    $approve = hrWorkspaceModal($source, 'hr-payroll-approve');
    $payment = hrWorkspaceModal($source, 'hr-payroll-payment');
    $reversal = hrWorkspaceModal($source, 'hr-payroll-reversal');

    expect($source)
        ->toContain('<flux:modal.trigger name="hr-payroll-create">')
        ->toContain('wire:click="openApprove(')
        ->toContain('wire:click="openPayment(')
        ->toContain('wire:click="openReversal(')
        ->toContain('wire:click="calculate(')
        ->toContain('wire:click="post(')
        ->toContain("\$this->modal('hr-payroll-create')->close()")
        ->toContain("\$this->modal('hr-payroll-approve')->close()")
        ->toContain("\$this->modal('hr-payroll-payment')->close()")
        ->toContain("\$this->modal('hr-payroll-reversal')->close()")
        ->and($create)->toContain('wire:submit="create"')->toContain('wire:model="period_start"')
        ->and($approve)->toContain('wire:submit="approve"')->toContain('wire:model="action_note"')
        ->and($payment)->toContain('wire:submit="pay"')->toContain('wire:model="bank_account_id"')->toContain('wire:model="payment_reference"')
        ->and($reversal)->toContain('wire:submit="reverse"')->toContain('wire:model="reversal_date"')->toContain('wire:model="action_note"')
        ->and(substr_count($source, '<flux:modal.close>'))->toBe(4);
});

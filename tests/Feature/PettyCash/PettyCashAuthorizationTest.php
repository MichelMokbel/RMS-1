<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('redirects guests from petty cash index', function () {
    $this->get('/petty-cash')->assertRedirect('/login');
});

it('allows admin role to view petty cash index', function () {
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $createExpenseUrl = route('payables.invoices.create', [
        'document_type' => 'expense',
        'expense_channel' => 'petty_cash',
    ]);

    $this->actingAs($user)
        ->get('/petty-cash')
        ->assertOk()
        ->assertSee('Petty Cash')
        ->assertSee('Wallets')
        ->assertSee('Funding')
        ->assertSee('Reconciliations')
        ->assertSee('Create PC Expense')
        ->assertSee($createExpenseUrl)
        ->assertSeeInOrder([
            'Create PC Expense',
            'Back to Payables',
        ]);
});

<?php

use App\Models\ArInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function makeReceivablesReportManager(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::firstOrCreate(['name' => 'manager'], ['guard_name' => 'web']);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

it('excludes voided invoices from receivables report exports', function () {
    $user = makeReceivablesReportManager();

    $active = ArInvoice::factory()->create([
        'status' => 'issued',
        'type' => 'invoice',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'total_cents' => 10000,
        'balance_cents' => 10000,
    ]);

    $voided = ArInvoice::factory()->create([
        'status' => 'voided',
        'type' => 'invoice',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'total_cents' => 25000,
        'balance_cents' => 25000,
        'voided_at' => now(),
        'voided_by' => $user->id,
        'void_reason' => 'Legacy void',
    ]);

    $response = $this->actingAs($user)
        ->get(route('reports.receivables.print'))
        ->assertOk();

    $response
        ->assertSee($active->customer?->name ?? '')
        ->assertDontSee($voided->customer?->name ?? '');
});

it('excludes voided invoices from the receivables report screen', function () {
    $user = makeReceivablesReportManager();

    $active = ArInvoice::factory()->create([
        'status' => 'issued',
        'type' => 'invoice',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'total_cents' => 10000,
        'balance_cents' => 10000,
    ]);

    $voided = ArInvoice::factory()->create([
        'status' => 'voided',
        'type' => 'invoice',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'total_cents' => 99000,
        'balance_cents' => 99000,
        'voided_at' => now(),
        'voided_by' => $user->id,
        'void_reason' => 'Legacy void',
    ]);

    Volt::actingAs($user);

    Volt::test('reports.receivables')
        ->assertSee($active->customer?->name ?? '')
        ->assertDontSee($voided->customer?->name ?? '')
        ->assertSee('100.00')
        ->assertDontSee('990.00');
});

<?php

use App\Models\ArInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function makeSummaryManager(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::firstOrCreate(['name' => 'manager'], ['guard_name' => 'web']);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

it('excludes voided invoices from receivables summary exports', function () {
    $user = makeSummaryManager();

    ArInvoice::factory()->create([
        'type' => 'invoice',
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'total_cents' => 10000,
        'balance_cents' => 10000,
    ]);

    ArInvoice::factory()->create([
        'type' => 'invoice',
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'total_cents' => 25000,
        'balance_cents' => 25000,
        'voided_at' => now(),
        'voided_by' => $user->id,
        'void_reason' => 'Legacy void',
    ]);

    $this->actingAs($user)
        ->get(route('reports.receivables-summary.print'))
        ->assertOk()
        ->assertViewHas('summary', fn (array $summary) => $summary['total_invoiced_cents'] === 10000
            && $summary['total_balance_cents'] === 10000
            && collect($summary['by_status'])->sum('count') === 1);
});

it('excludes voided invoices from the receivables summary screen', function () {
    $user = makeSummaryManager();

    ArInvoice::factory()->create([
        'type' => 'invoice',
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'total_cents' => 10000,
        'balance_cents' => 10000,
    ]);

    ArInvoice::factory()->create([
        'type' => 'invoice',
        'status' => 'paid',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'total_cents' => 99000,
        'paid_total_cents' => 99000,
        'balance_cents' => 0,
        'voided_at' => now(),
        'voided_by' => $user->id,
        'void_reason' => 'Legacy void',
    ]);

    Volt::actingAs($user);

    Volt::test('reports.receivables-summary')
        ->assertSee('100.00')
        ->assertDontSee('990.00');
});

<?php

use App\Models\ArInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('manager', 'web');
});

it('sales entry monthly print includes all filtered invoices beyond the old export cap', function () {
    ArInvoice::factory()->count(2001)->create([
        'type' => 'invoice',
        'status' => 'issued',
        'issue_date' => '2026-03-15',
        'total_cents' => 100,
        'subtotal_cents' => 100,
        'balance_cents' => 100,
    ]);

    ArInvoice::factory()->create([
        'type' => 'invoice',
        'status' => 'issued',
        'issue_date' => '2026-04-01',
        'total_cents' => 900,
        'subtotal_cents' => 900,
        'balance_cents' => 900,
    ]);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(
        route('reports.sales-entry-monthly.print').'?date_from=2026-03-01&date_to=2026-03-31'
    );

    $response->assertOk();
    $response->assertSee('2026-03');
    $response->assertSee('2,001', false);
    $response->assertSee('2001.00', false);
    $response->assertDontSee('2026-04');
});

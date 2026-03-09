<?php

use App\Models\ArInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('manager', 'web');
});

it('sales entry daily print respects date range filters', function () {
    ArInvoice::factory()->create([
        'type' => 'invoice',
        'status' => 'issued',
        'issue_date' => '2026-03-01',
        'invoice_number' => 'INV-DAILY-001',
        'total_cents' => 1200,
        'subtotal_cents' => 1200,
    ]);

    ArInvoice::factory()->create([
        'type' => 'invoice',
        'status' => 'issued',
        'issue_date' => '2026-03-02',
        'invoice_number' => 'INV-DAILY-002',
        'total_cents' => 2300,
        'subtotal_cents' => 2300,
    ]);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(
        route('reports.sales-entry-daily.print').'?date_from=2026-03-01&date_to=2026-03-01'
    );

    $response->assertStatus(200);
    $response->assertSee('INV-DAILY-001');
    $response->assertDontSee('INV-DAILY-002');
});


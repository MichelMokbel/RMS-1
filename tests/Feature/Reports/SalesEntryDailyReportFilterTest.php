<?php

use App\Models\ArInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
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

it('sales entry daily print includes more than 500 rows', function () {
    ArInvoice::factory()
        ->count(501)
        ->state(new Sequence(
            fn (Sequence $sequence) => [
                'type' => 'invoice',
                'status' => 'issued',
                'issue_date' => '2026-03-01',
                'invoice_number' => sprintf('INV-DAILY-%03d', $sequence->index + 1),
                'total_cents' => 1000,
                'subtotal_cents' => 1000,
            ],
        ))
        ->create();

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(
        route('reports.sales-entry-daily.print').'?date_from=2026-03-01&date_to=2026-03-01'
    );

    $response->assertStatus(200);
    $response->assertSee('INV-DAILY-501');
});

it('sales report print includes more than 500 rows', function () {
    ArInvoice::factory()
        ->count(501)
        ->state(new Sequence(
            fn (Sequence $sequence) => [
                'type' => 'invoice',
                'status' => 'issued',
                'issue_date' => '2026-03-01',
                'invoice_number' => sprintf('INV-SALES-%03d', $sequence->index + 1),
                'total_cents' => 1000,
                'subtotal_cents' => 1000,
            ],
        ))
        ->create();

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(
        route('reports.sales.print').'?date_from=2026-03-01&date_to=2026-03-01'
    );

    $response->assertStatus(200);
    $response->assertSee('INV-SALES-501');
});

it('sales report all status excludes voided invoices by default', function () {
    ArInvoice::factory()->create([
        'type' => 'invoice',
        'status' => 'issued',
        'issue_date' => '2026-03-01',
        'invoice_number' => 'INV-ACTIVE-001',
        'total_cents' => 1000,
        'subtotal_cents' => 1000,
    ]);

    ArInvoice::factory()->create([
        'type' => 'invoice',
        'status' => 'voided',
        'issue_date' => '2026-03-01',
        'invoice_number' => 'INV-VOIDED-001',
        'total_cents' => 9000,
        'subtotal_cents' => 9000,
    ]);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(
        route('reports.sales.print').'?date_from=2026-03-01&date_to=2026-03-01'
    );

    $response->assertStatus(200);
    $response->assertSee('INV-ACTIVE-001');
    $response->assertDontSee('INV-VOIDED-001');
    $response->assertSee('10.00', false);
    $response->assertDontSee('90.00', false);
});

it('sales report can still filter voided invoices explicitly', function () {
    ArInvoice::factory()->create([
        'type' => 'invoice',
        'status' => 'issued',
        'issue_date' => '2026-03-01',
        'invoice_number' => 'INV-ACTIVE-001',
        'total_cents' => 1000,
        'subtotal_cents' => 1000,
    ]);

    ArInvoice::factory()->create([
        'type' => 'invoice',
        'status' => 'voided',
        'issue_date' => '2026-03-01',
        'invoice_number' => 'INV-VOIDED-001',
        'total_cents' => 9000,
        'subtotal_cents' => 9000,
    ]);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $response = $this->actingAs($user)->get(
        route('reports.sales.print').'?date_from=2026-03-01&date_to=2026-03-01&status=voided'
    );

    $response->assertStatus(200);
    $response->assertSee('INV-VOIDED-001');
    $response->assertDontSee('INV-ACTIVE-001');
    $response->assertSee('90.00', false);
});

<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Support\Money\MinorUnits;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('admin users can visit the dashboard', function () {
    Role::findOrCreate('admin', 'web');
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertStatus(200);
});

test('non-admin users cannot visit the dashboard', function () {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertForbidden();
});

test('dashboard revenue uses paid ar invoice amounts, not orders totals', function () {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $customer = Customer::factory()->create();

    ArInvoice::factory()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'paid',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
        'total_cents' => 12000,
        'paid_total_cents' => 12000,
        'balance_cents' => 0,
    ]);

    ArInvoice::factory()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'partially_paid',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
        'total_cents' => 25000,
        'paid_total_cents' => 5000,
        'balance_cents' => 20000,
    ]);

    ArInvoice::factory()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
        'total_cents' => 77700,
        'paid_total_cents' => 0,
        'balance_cents' => 77700,
    ]);

    Order::factory()->create([
        'branch_id' => 1,
        'status' => 'Delivered',
        'scheduled_date' => now()->toDateString(),
        'total_amount' => 9999.00,
    ]);

    $scale = max(1, MinorUnits::posScale());
    $digits = MinorUnits::scaleDigits($scale);
    $expectedRevenue = number_format((12000 + 5000) / $scale, $digits);

    $response = $this->actingAs($admin)->get(route('dashboard'));
    $response->assertOk();
    $response->assertSeeInOrder([
        'Revenue (Paid Invoices)',
        $expectedRevenue,
    ], false);
});

test('dashboard finance kpi labels are not duplicated and chart payload exists', function () {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $response = $this->actingAs($admin)->get(route('dashboard'));
    $response->assertOk();
    $response->assertSee('data-dashboard-charts', false);

    $content = $response->getContent();
    expect(substr_count($content, 'Payables Outstanding'))->toBe(1);
    expect(substr_count($content, 'Expenses (Range)'))->toBe(1);
});

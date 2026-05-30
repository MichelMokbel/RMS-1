<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\MealSubscription;
use App\Models\Order;
use App\Models\User;
use App\Support\Money\MinorUnits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    DB::table('branches')->updateOrInsert(
        ['id' => 1],
        ['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );
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

test('authenticated non-admin users can visit the dashboard', function () {
    Role::findOrCreate('cashier', 'web');
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('cashier');

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
    $response->assertSee('Quick Actions');
    $response->assertSee('Create PO');
    $response->assertDontSee('Create Invoice');
    $response->assertDontSee('Add Customer Payment');
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

test('non-admin users do not see admin-only receivables kpis or charts', function () {
    Role::findOrCreate('manager', 'web');
    $manager = User::factory()->create(['status' => 'active']);
    $manager->assignRole('manager');

    $response = $this->actingAs($manager)->get(route('dashboard'));
    $response->assertOk();
    $response->assertDontSee('Revenue (Paid Invoices)');
    $response->assertDontSee('Receivables Outstanding');
    $response->assertDontSee('data-dashboard-charts', false);
    $response->assertDontSee('Receivables Split');
    $response->assertDontSee('Invoice Status Mix (Amount)');
});

test('dashboard quick actions are scoped by user access', function () {
    Role::findOrCreate('staff', 'web');
    $staff = User::factory()->create(['status' => 'active']);
    $staff->assignRole('staff');

    $response = $this->actingAs($staff)->get(route('dashboard'));
    $response->assertOk();
    $response->assertSee('Quick Actions');
    $response->assertSee('Create PO');
    $response->assertDontSee('Create Invoice');
    $response->assertDontSee('Add Customer Payment');
});

test('dashboard quick actions show only the supported shortcuts', function () {
    Role::findOrCreate('manager', 'web');
    $manager = User::factory()->create(['status' => 'active']);
    $manager->assignRole('manager');

    $response = $this->actingAs($manager)->get(route('dashboard'));
    $response->assertOk();
    $response->assertSee('Quick Actions');
    $response->assertSee('Create PO');
    $response->assertSee('Create Invoice');
    $response->assertSee('Add Customer Payment');
    $response->assertDontSee('Create Order');
});

test('dashboard keeps expired not renewed subscriptions in the subscription attention section', function () {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $customer = Customer::factory()->create(['name' => 'Low Meals Customer']);
    $expiredCustomer = Customer::factory()->create(['name' => 'Expired Customer']);
    $renewedExpiredCustomer = Customer::factory()->create(['name' => 'Renewed Expired Customer']);

    MealSubscription::factory()->create([
        'subscription_code' => 'SUB-LOW-001',
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'plan_meals_total' => 20,
        'meals_used' => 19,
    ]);

    MealSubscription::factory()->create([
        'subscription_code' => 'SUB-EXP-001',
        'customer_id' => $expiredCustomer->id,
        'branch_id' => 1,
        'status' => 'expired',
        'start_date' => '2025-01-01',
        'end_date' => '2025-01-31',
    ]);

    MealSubscription::factory()->create([
        'subscription_code' => 'SUB-EXP-OLD',
        'customer_id' => $renewedExpiredCustomer->id,
        'branch_id' => 1,
        'status' => 'expired',
        'start_date' => '2025-02-01',
        'end_date' => '2025-02-28',
    ]);

    MealSubscription::factory()->create([
        'subscription_code' => 'SUB-EXP-RENEWAL',
        'customer_id' => $renewedExpiredCustomer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-03-05',
    ]);

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('Subscription Attention Needed');
    $response->assertSee('Low Meals Customer');
    $response->assertSee('Low meals');
    $response->assertSee('Expired Customer');
    $response->assertSee('Expired not renewed');
    $response->assertSee('Expired not renewed: 1');
    $response->assertDontSee('Renewed Expired Customer');
});

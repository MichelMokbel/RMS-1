<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\MealSubscription;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function seedCustomerPortalBranch(int $id = 1): void
{
    DB::table('branches')->updateOrInsert(
        ['id' => $id],
        ['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );
}

beforeEach(function () {
    Role::findOrCreate('customer', 'web');
    seedCustomerPortalBranch();
});

it('returns only the authenticated customer financial and order data', function () {
    $customer = Customer::factory()->create(['phone_verified_at' => now()]);
    $otherCustomer = Customer::factory()->create(['phone_verified_at' => now()]);

    $user = User::factory()->create([
        'email' => 'portal@example.com',
        'customer_id' => $customer->id,
    ]);
    $user->assignRole('customer');

    $otherUser = User::factory()->create([
        'email' => 'other@example.com',
        'customer_id' => $otherCustomer->id,
    ]);
    $otherUser->assignRole('customer');

    MealSubscription::factory()->create([
        'customer_id' => $customer->id,
        'status' => 'active',
    ]);
    MealSubscription::factory()->create([
        'customer_id' => $otherCustomer->id,
        'status' => 'active',
    ]);

    $customerOrder = Order::factory()->create([
        'customer_id' => $customer->id,
        'total_amount' => 120.500,
    ]);
    Order::factory()->create([
        'customer_id' => $otherCustomer->id,
        'total_amount' => 75.000,
    ]);

    $overdueInvoice = ArInvoice::factory()->issued()->create([
        'customer_id' => $customer->id,
        'total_cents' => 15000,
        'paid_total_cents' => 0,
        'balance_cents' => 15000,
        'due_date' => now()->subDay()->toDateString(),
    ]);
    $todayInvoice = ArInvoice::factory()->issued()->create([
        'customer_id' => $customer->id,
        'total_cents' => 5000,
        'paid_total_cents' => 0,
        'balance_cents' => 5000,
        'due_date' => now()->toDateString(),
    ]);
    $otherInvoice = ArInvoice::factory()->issued()->create([
        'customer_id' => $otherCustomer->id,
        'total_cents' => 99000,
        'paid_total_cents' => 0,
        'balance_cents' => 99000,
        'due_date' => now()->addDay()->toDateString(),
    ]);

    Payment::factory()->create([
        'customer_id' => $customer->id,
        'amount_cents' => 8000,
        'received_at' => now(),
    ]);
    Payment::factory()->create([
        'customer_id' => $otherCustomer->id,
        'amount_cents' => 12000,
        'received_at' => now(),
    ]);

    Sanctum::actingAs($user, ['customer:*']);

    $this->getJson('/api/customer/dashboard')
        ->assertOk()
        ->assertJsonPath('summary.active_subscriptions', 1)
        ->assertJsonPath('summary.unpaid_invoice_count', 2)
        ->assertJsonPath('summary.outstanding_balance.cents', 20000)
        ->assertJsonPath('summary.overdue_balance.cents', 15000)
        ->assertJsonPath('due_payments.overdue.cents', 15000)
        ->assertJsonPath('due_payments.due_today.cents', 5000);

    $this->getJson('/api/customer/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $customerOrder->id);

    $this->getJson('/api/customer/invoices')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/customer/payments')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/customer/subscriptions')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson("/api/customer/invoices/{$otherInvoice->id}")
        ->assertNotFound();

    $this->getJson("/api/customer/invoices/{$overdueInvoice->id}")
        ->assertOk()
        ->assertJsonPath('data.amounts.balance.cents', 15000);

    $this->getJson("/api/customer/invoices/{$todayInvoice->id}")
        ->assertOk()
        ->assertJsonPath('data.due_bucket', 'due_today');
});

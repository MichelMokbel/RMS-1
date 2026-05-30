<?php

use App\Models\ArInvoice;
use App\Models\ArInvoiceItem;
use App\Models\Customer;
use App\Models\MealSubscription;
use App\Models\MenuItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');

    DB::table('branches')->updateOrInsert(
        ['id' => 1],
        ['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );
});

it('shows invoices with wrong subscription plan items on the audit page', function () {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $customer = Customer::factory()->create(['name' => 'Invoice Audit Customer']);

    $expectedItem = MenuItem::factory()->create([
        'code' => 'SUB-20',
        'name' => '20 Meals Plan',
        'selling_price_per_unit' => '20.000',
    ]);

    $wrongPlanItem = MenuItem::factory()->create([
        'code' => 'SUB-26',
        'name' => '26 Meals Plan',
        'selling_price_per_unit' => '26.000',
    ]);

    config()->set('subscriptions.plan_menu_item_ids', [
        20 => $expectedItem->id,
        26 => $wrongPlanItem->id,
    ]);

    $payment = Payment::factory()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'amount_cents' => 40000,
        'reference' => 'PAY-SUB-AUDIT',
    ]);

    $subscription = MealSubscription::factory()->create([
        'subscription_code' => 'SUB-AUDIT-001',
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'plan_meals_total' => 20,
        'source_payment_id' => $payment->id,
        'uses_invoice_tracking' => true,
    ]);

    $correctInvoice = ArInvoice::factory()->issued()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-AUDIT-CORRECT',
        'total_cents' => 20000,
        'paid_total_cents' => 20000,
        'balance_cents' => 0,
    ]);

    ArInvoiceItem::factory()->create([
        'invoice_id' => $correctInvoice->id,
        'description' => 'Correct plan item',
        'qty' => '1.000',
        'line_total_cents' => 20000,
        'sellable_type' => MenuItem::class,
        'sellable_id' => $expectedItem->id,
        'name_snapshot' => $expectedItem->name,
        'sku_snapshot' => $expectedItem->code,
        'meta' => ['is_subscription' => true],
    ]);

    PaymentAllocation::factory()->create([
        'payment_id' => $payment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $correctInvoice->id,
        'amount_cents' => 20000,
    ]);

    $wrongInvoice = ArInvoice::factory()->issued()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-AUDIT-WRONG',
        'total_cents' => 20000,
        'paid_total_cents' => 20000,
        'balance_cents' => 0,
    ]);

    ArInvoiceItem::factory()->create([
        'invoice_id' => $wrongInvoice->id,
        'description' => 'Wrong plan item',
        'qty' => '1.000',
        'line_total_cents' => 20000,
        'sellable_type' => MenuItem::class,
        'sellable_id' => $wrongPlanItem->id,
        'name_snapshot' => $wrongPlanItem->name,
        'sku_snapshot' => $wrongPlanItem->code,
        'meta' => ['is_subscription' => true],
    ]);

    PaymentAllocation::factory()->create([
        'payment_id' => $payment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $wrongInvoice->id,
        'amount_cents' => 20000,
    ]);

    $response = $this->actingAs($admin)->get(route('subscriptions.invoice-audit', $subscription));

    $response->assertOk();
    $response->assertSee('Subscription Invoice Audit');
    $response->assertSee('INV-AUDIT-WRONG');
    $response->assertSee('Wrong plan item');
    $response->assertSee('SUB-26');
    $response->assertDontSee('INV-AUDIT-CORRECT');
});

it('lists all linked invoices when the issues filter is disabled', function () {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $customer = Customer::factory()->create();
    $expectedItem = MenuItem::factory()->create(['code' => 'SUB-20-ALL', 'name' => '20 Meals Plan']);
    $wrongPlanItem = MenuItem::factory()->create(['code' => 'SUB-26-ALL', 'name' => '26 Meals Plan']);

    config()->set('subscriptions.plan_menu_item_ids', [
        20 => $expectedItem->id,
        26 => $wrongPlanItem->id,
    ]);

    $payment = Payment::factory()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'reference' => 'PAY-SUB-ALL',
    ]);

    $subscription = MealSubscription::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'plan_meals_total' => 20,
        'source_payment_id' => $payment->id,
        'uses_invoice_tracking' => true,
    ]);

    $correctInvoice = ArInvoice::factory()->issued()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-ALL-CORRECT',
        'total_cents' => 10000,
        'paid_total_cents' => 10000,
        'balance_cents' => 0,
    ]);

    ArInvoiceItem::factory()->create([
        'invoice_id' => $correctInvoice->id,
        'qty' => '1.000',
        'line_total_cents' => 10000,
        'sellable_type' => MenuItem::class,
        'sellable_id' => $expectedItem->id,
        'name_snapshot' => $expectedItem->name,
        'sku_snapshot' => $expectedItem->code,
    ]);

    PaymentAllocation::factory()->create([
        'payment_id' => $payment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $correctInvoice->id,
        'amount_cents' => 10000,
    ]);

    $wrongInvoice = ArInvoice::factory()->issued()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-ALL-WRONG',
        'total_cents' => 10000,
        'paid_total_cents' => 10000,
        'balance_cents' => 0,
    ]);

    ArInvoiceItem::factory()->create([
        'invoice_id' => $wrongInvoice->id,
        'qty' => '1.000',
        'line_total_cents' => 10000,
        'sellable_type' => MenuItem::class,
        'sellable_id' => $wrongPlanItem->id,
        'name_snapshot' => $wrongPlanItem->name,
        'sku_snapshot' => $wrongPlanItem->code,
    ]);

    PaymentAllocation::factory()->create([
        'payment_id' => $payment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $wrongInvoice->id,
        'amount_cents' => 10000,
    ]);

    $response = $this->actingAs($admin)->get(route('subscriptions.invoice-audit', [
        'subscription' => $subscription,
        'show_only_issues' => 0,
    ]));

    $response->assertOk();
    $response->assertSee('INV-ALL-CORRECT');
    $response->assertSee('INV-ALL-WRONG');
});

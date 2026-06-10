<?php

use App\Models\ArInvoice;
use App\Models\ArInvoiceItem;
use App\Models\Customer;
use App\Models\MealSubscription;
use App\Models\MenuItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Subscriptions\SubscriptionPaymentLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('branches')->updateOrInsert(
        ['id' => 1],
        ['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );
});

it('aggregates fractional allocated coverage across invoices when resyncing payment-linked meals', function () {
    $customer = Customer::factory()->create();
    $planItem = MenuItem::factory()->create([
        'code' => 'MI-000094',
        'name' => 'Daily Dish Monthly 26 Days',
    ]);

    config()->set('subscriptions.plan_menu_item_ids', [
        26 => $planItem->id,
    ]);

    $payment = Payment::factory()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'amount_cents' => 110000,
    ]);

    $subscription = MealSubscription::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'plan_meals_total' => 26,
        'meals_used' => 0,
        'source_payment_id' => $payment->id,
        'uses_invoice_tracking' => true,
    ]);

    foreach (range(1, 25) as $index) {
        $invoice = ArInvoice::factory()->issued()->create([
            'branch_id' => 1,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-FULL-'.$index,
            'total_cents' => 4230,
            'paid_total_cents' => 4230,
            'balance_cents' => 0,
        ]);

        ArInvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'qty' => '1.000',
            'line_total_cents' => 4230,
            'sellable_type' => MenuItem::class,
            'sellable_id' => $planItem->id,
            'name_snapshot' => $planItem->name,
            'sku_snapshot' => $planItem->code,
            'meta' => ['is_subscription' => true],
        ]);

        PaymentAllocation::factory()->create([
            'payment_id' => $payment->id,
            'allocatable_type' => ArInvoice::class,
            'allocatable_id' => $invoice->id,
            'amount_cents' => 4230,
        ]);
    }

    $shortInvoice = ArInvoice::factory()->issued()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-SHORT',
        'total_cents' => 4230,
        'paid_total_cents' => 4230,
        'balance_cents' => 0,
    ]);

    ArInvoiceItem::factory()->create([
        'invoice_id' => $shortInvoice->id,
        'qty' => '1.000',
        'line_total_cents' => 4230,
        'sellable_type' => MenuItem::class,
        'sellable_id' => $planItem->id,
        'name_snapshot' => $planItem->name,
        'sku_snapshot' => $planItem->code,
        'meta' => ['is_subscription' => true],
    ]);

    PaymentAllocation::factory()->create([
        'payment_id' => $payment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $shortInvoice->id,
        'amount_cents' => 4210,
    ]);

    $changeInvoice = ArInvoice::factory()->issued()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'invoice_number' => 'INV-CHANGE',
        'total_cents' => 4230,
        'paid_total_cents' => 4230,
        'balance_cents' => 0,
    ]);

    ArInvoiceItem::factory()->create([
        'invoice_id' => $changeInvoice->id,
        'qty' => '1.000',
        'line_total_cents' => 4230,
        'sellable_type' => MenuItem::class,
        'sellable_id' => $planItem->id,
        'name_snapshot' => $planItem->name,
        'sku_snapshot' => $planItem->code,
        'meta' => ['is_subscription' => true],
    ]);

    PaymentAllocation::factory()->create([
        'payment_id' => $payment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $changeInvoice->id,
        'amount_cents' => 40,
    ]);

    $count = app(SubscriptionPaymentLinkService::class)->resyncMealsUsed($subscription);

    expect($count)->toBe(26);
    expect($subscription->fresh()->meals_used)->toBe(26);
    expect($subscription->fresh()->status)->toBe('expired');
});

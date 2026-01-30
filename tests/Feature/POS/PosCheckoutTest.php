<?php

use App\Events\SaleClosed;
use App\Models\MenuItem;
use App\Models\Sale;
use App\Models\User;
use App\Services\POS\PosCheckoutService;
use App\Services\POS\PosShiftService;
use App\Services\Sales\SaleService;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('manager');
    Role::findOrCreate('cashier');
});

it('cannot checkout without an active shift', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $item = MenuItem::factory()->create([
        'selling_price_per_unit' => '10.000',
        'tax_rate' => '0.00',
    ]);

    /** @var SaleService $sales */
    $sales = app(SaleService::class);
    $sale = $sales->create(['branch_id' => 1, 'customer_id' => null], $cashier->id);
    $sales->addMenuItem($sale, $item, qty: '1.000');

    /** @var PosCheckoutService $checkout */
    $checkout = app(PosCheckoutService::class);

    expect(fn () => $checkout->checkout($sale, [
        ['method' => 'cash', 'amount_cents' => 10000],
    ], $cashier->id))->toThrow(ValidationException::class);
});

it('creates sale items and computes totals correctly', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $item = MenuItem::factory()->create([
        'selling_price_per_unit' => '10.000',
        'tax_rate' => '10.00', // 10%
    ]);

    /** @var SaleService $sales */
    $sales = app(SaleService::class);
    $sale = $sales->create(['branch_id' => 1, 'customer_id' => null], $cashier->id);
    $sales->addMenuItem($sale, $item, qty: '2.000', discountCents: 1000);

    $sale = $sale->fresh();

    // subtotal = 2 * 10.000 = 20.000 => 20000
    // discount = 1.000 => 1000
    // net = 19000; tax 10% => 1900; total => 20900
    expect($sale->subtotal_cents)->toBe(20000);
    expect($sale->discount_total_cents)->toBe(1000);
    expect($sale->tax_total_cents)->toBe(1900);
    expect($sale->total_cents)->toBe(20900);
    expect($sale->due_total_cents)->toBe(20900);
});

it('supports split payments and closes the sale', function () {
    Event::fake([SaleClosed::class]);

    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $item = MenuItem::factory()->create([
        'selling_price_per_unit' => '10.000',
        'tax_rate' => '0.00',
    ]);

    /** @var SaleService $sales */
    $sales = app(SaleService::class);
    $sale = $sales->create(['branch_id' => 1, 'customer_id' => null], $cashier->id);
    $sales->addMenuItem($sale, $item, qty: '1.000');

    // Open shift for cashier
    /** @var PosShiftService $shifts */
    $shifts = app(PosShiftService::class);
    $shifts->open(1, $cashier->id, 5000, $cashier->id);

    $sale = $sale->fresh();
    expect($sale->total_cents)->toBe(10000);

    /** @var PosCheckoutService $checkout */
    $checkout = app(PosCheckoutService::class);
    $closed = $checkout->checkout($sale, [
        ['method' => 'cash', 'amount_cents' => 3000],
        ['method' => 'card', 'amount_cents' => 7000],
    ], $cashier->id);

    expect($closed->status)->toBe('closed');
    expect($closed->due_total_cents)->toBe(0);
    expect($closed->paid_total_cents)->toBe(10000);
    expect($closed->sale_number)->not()->toBeNull();

    Event::assertDispatched(SaleClosed::class);
});

it('requires manager permission and reason to void a sale', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $manager = User::factory()->create();
    $manager->assignRole('manager');

    /** @var SaleService $sales */
    $sales = app(SaleService::class);
    $sale = $sales->create(['branch_id' => 1, 'customer_id' => null], $cashier->id);

    expect(fn () => $sales->void($sale, $cashier, ''))
        ->toThrow(ValidationException::class);

    expect(fn () => $sales->void($sale, $cashier, 'Customer requested'))
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    $voided = $sales->void($sale, $manager, 'Customer requested');
    expect($voided->status)->toBe('voided');
    expect($voided->void_reason)->toBe('Customer requested');
});

it('persists order type takeaway and dine_in', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    /** @var SaleService $sales */
    $sales = app(SaleService::class);

    $saleTakeaway = $sales->create(['branch_id' => 1, 'customer_id' => null, 'order_type' => 'takeaway'], $cashier->id);
    expect($saleTakeaway->order_type)->toBe('takeaway');

    $saleDineIn = $sales->create(['branch_id' => 1, 'customer_id' => null, 'order_type' => 'dine_in'], $cashier->id);
    expect($saleDineIn->order_type)->toBe('dine_in');
});

it('generates POS reference on create for POS source', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    /** @var SaleService $sales */
    $sales = app(SaleService::class);
    $sale = $sales->create([
        'branch_id' => 1,
        'customer_id' => null,
        'source' => 'pos',
    ], $cashier->id);

    expect($sale->pos_reference)->not()->toBeNull();
    expect($sale->pos_reference)->toStartWith('POS');
});

it('increments quantity when adding the same item twice', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $item = MenuItem::factory()->create([
        'selling_price_per_unit' => '10.000',
        'tax_rate' => '0.00',
    ]);

    /** @var SaleService $sales */
    $sales = app(SaleService::class);
    $sale = $sales->create(['branch_id' => 1, 'customer_id' => null], $cashier->id);
    $sales->addMenuItem($sale, $item, qty: '1.000');
    $sales->addMenuItem($sale, $item, qty: '1.000');

    $sale = $sale->fresh(['items']);
    expect($sale->items->count())->toBe(1);
    expect((string) $sale->items->first()->qty)->toBe('2.000');
});

it('applies global discount to totals correctly', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $item = MenuItem::factory()->create([
        'selling_price_per_unit' => '10.000',
        'tax_rate' => '0.00',
    ]);

    /** @var SaleService $sales */
    $sales = app(SaleService::class);
    $sale = $sales->create(['branch_id' => 1, 'customer_id' => null], $cashier->id);
    $sales->addMenuItem($sale, $item, qty: '2.000');

    $sale = $sale->fresh();
    expect($sale->total_cents)->toBe(20000);

    $sales->setGlobalDiscount($sale, 3000); // 30.00 off
    $sale = $sale->fresh();
    expect($sale->global_discount_cents)->toBe(3000);
    expect($sale->total_cents)->toBe(17000);
    expect($sale->due_total_cents)->toBe(17000);
});

it('supports percent discounts on line and invoice', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $item = MenuItem::factory()->create([
        'selling_price_per_unit' => '10.000',
        'tax_rate' => '0.00',
    ]);

    /** @var SaleService $sales */
    $sales = app(SaleService::class);
    $sale = $sales->create(['branch_id' => 1, 'customer_id' => null], $cashier->id);
    $line = $sales->addMenuItem($sale, $item, qty: '1.000');

    $sales->updateItemDiscount($line, 'percent', '10');
    $sale = $sale->fresh();
    expect($sale->discount_total_cents)->toBe(1000);
    expect($sale->total_cents)->toBe(9000);

    $sales->setGlobalDiscountValue($sale, 'percent', '10');
    $sale = $sale->fresh();
    expect($sale->global_discount_cents)->toBe(900);
    expect($sale->total_cents)->toBe(8100);
});

it('quick pay cash closes sale with single payment', function () {
    Event::fake([SaleClosed::class]);

    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $item = MenuItem::factory()->create([
        'selling_price_per_unit' => '15.000',
        'tax_rate' => '0.00',
    ]);

    /** @var SaleService $sales */
    $sales = app(SaleService::class);
    $sale = $sales->create(['branch_id' => 1, 'customer_id' => null], $cashier->id);
    $sales->addMenuItem($sale, $item, qty: '1.000');

    /** @var PosShiftService $shifts */
    $shifts = app(PosShiftService::class);
    $shifts->open(1, $cashier->id, 0, $cashier->id);

    /** @var PosCheckoutService $checkout */
    $checkout = app(PosCheckoutService::class);
    $closed = $checkout->quickPay($sale->fresh(), 'cash', $cashier->id);

    expect($closed->status)->toBe('closed');
    expect($closed->due_total_cents)->toBe(0);
    expect($closed->paid_total_cents)->toBe(15000);
    Event::assertDispatched(SaleClosed::class);
});

it('hold and recall preserves cart and totals', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $item = MenuItem::factory()->create([
        'selling_price_per_unit' => '10.000',
        'tax_rate' => '0.00',
    ]);

    /** @var SaleService $sales */
    $sales = app(SaleService::class);
    $sale = $sales->create(['branch_id' => 1, 'customer_id' => null], $cashier->id);
    $sales->addMenuItem($sale, $item, qty: '3.000');

    $sale = $sale->fresh();
    expect($sale->total_cents)->toBe(30000);
    expect($sale->items()->count())->toBe(1);

    $sales->hold($sale, $cashier->id);
    $sale = $sale->fresh();
    expect($sale->held_at)->not()->toBeNull();
    expect($sale->held_by)->toBe($cashier->id);

    $recalled = $sales->recall($sale, $cashier->id);
    $recalled = $recalled->fresh(['items']);
    expect($recalled->held_at)->toBeNull();
    expect($recalled->total_cents)->toBe(30000);
    expect($recalled->items->count())->toBe(1);
});

it('KOT print route is authorized and renders', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $item = MenuItem::factory()->create(['name' => 'Test Item', 'selling_price_per_unit' => '5.000', 'tax_rate' => '0']);
    /** @var SaleService $sales */
    $sales = app(SaleService::class);
    $sale = $sales->create(['branch_id' => 1, 'customer_id' => null], $cashier->id);
    $sales->addMenuItem($sale, $item, qty: '1.000');

    $response = $this->actingAs($cashier)->get(route('sales.kot', $sale));

    $response->assertOk();
    $response->assertSee('KITCHEN ORDER TICKET');
    $response->assertSee('Test Item');
    $response->assertSee('1.000');
});

it('credit checkout creates AR invoice and closes sale', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $item = MenuItem::factory()->create([
        'selling_price_per_unit' => '10.000',
        'tax_rate' => '0.00',
    ]);

    /** @var SaleService $sales */
    $sales = app(SaleService::class);
    $sale = $sales->create([
        'branch_id' => 1,
        'customer_id' => \App\Models\Customer::factory()->create()->id,
        'source' => 'pos',
    ], $admin->id);
    $sales->addMenuItem($sale, $item, qty: '1.000');

    /** @var PosCheckoutService $checkout */
    $checkout = app(PosCheckoutService::class);
    $closed = $checkout->checkoutCredit($sale->fresh(), $admin->id);

    expect($closed->status)->toBe('closed');
    expect($closed->is_credit)->toBeTrue();
    expect($closed->credit_invoice_id)->not()->toBeNull();
});


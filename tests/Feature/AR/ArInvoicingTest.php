<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\User;
use App\Services\AR\ArAllocationService;
use App\Services\AR\ArInvoiceService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('manager');
    Role::findOrCreate('cashier');
});

it('issues invoices with sequential, concurrency-safe numbers per branch/year', function () {
    Carbon::setTestNow(Carbon::create(2026, 1, 29, 12, 0, 0));

    $user = User::factory()->create();
    $user->assignRole('manager');

    $customer = Customer::factory()->corporate()->create(['credit_terms_days' => 30]);

    /** @var ArInvoiceService $svc */
    $svc = app(ArInvoiceService::class);

    $a = $svc->createDraft(1, $customer->id, [
        ['description' => 'Item A', 'qty' => '1.000', 'unit_price_cents' => 10000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 10000],
    ], $user->id);
    $a = $svc->issue($a, $user->id);

    $b = $svc->createDraft(1, $customer->id, [
        ['description' => 'Item B', 'qty' => '1.000', 'unit_price_cents' => 5000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 5000],
    ], $user->id);
    $b = $svc->issue($b, $user->id);

    expect($a->invoice_number)->toStartWith('INV2026-');
    expect($b->invoice_number)->toStartWith('INV2026-');

    $na = (int) substr((string) $a->invoice_number, -6);
    $nb = (int) substr((string) $b->invoice_number, -6);
    expect($nb)->toBe($na + 1);
});

it('applies partial and full payments and updates invoice balance/status', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    $customer = Customer::factory()->corporate()->create();

    /** @var ArInvoiceService $invoices */
    $invoices = app(ArInvoiceService::class);
    $inv = $invoices->createDraft(1, $customer->id, [
        ['description' => 'Service', 'qty' => '1.000', 'unit_price_cents' => 10000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 10000],
    ], $user->id);
    $inv = $invoices->issue($inv, $user->id);

    /** @var ArAllocationService $alloc */
    $alloc = app(ArAllocationService::class);

    $alloc->createPaymentAndAllocate([
        'invoice_id' => $inv->id,
        'amount_cents' => 4000,
        'method' => 'bank',
    ], $user->id);

    $inv = ArInvoice::findOrFail($inv->id);
    expect($inv->status)->toBe('partially_paid');
    expect($inv->balance_cents)->toBe(6000);

    $alloc->createPaymentAndAllocate([
        'invoice_id' => $inv->id,
        'amount_cents' => 6000,
        'method' => 'bank',
    ], $user->id);

    $inv = ArInvoice::findOrFail($inv->id);
    expect($inv->status)->toBe('paid');
    expect($inv->balance_cents)->toBe(0);
});

it('creates and applies a credit note to reduce invoice balance', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    $customer = Customer::factory()->corporate()->create();

    /** @var ArInvoiceService $invoices */
    $invoices = app(ArInvoiceService::class);

    $inv = $invoices->createDraft(1, $customer->id, [
        ['description' => 'Service', 'qty' => '1.000', 'unit_price_cents' => 10000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 10000],
    ], $user->id);
    $inv = $invoices->issue($inv, $user->id);

    $credit = $invoices->createDraft(1, $customer->id, [
        ['description' => 'Return', 'qty' => '1.000', 'unit_price_cents' => -3000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => -3000],
    ], $user->id, type: 'credit_note');
    $credit = $invoices->issue($credit, $user->id);

    /** @var ArAllocationService $alloc */
    $alloc = app(ArAllocationService::class);
    $alloc->applyCreditNote($credit, $inv, $user->id);

    $inv = ArInvoice::findOrFail($inv->id);
    $credit = ArInvoice::findOrFail($credit->id);

    expect($inv->balance_cents)->toBe(7000);
    expect($credit->balance_cents)->toBe(0);
});

it('rejects payments that exceed invoice balance', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');

    $customer = Customer::factory()->corporate()->create();

    /** @var ArInvoiceService $invoices */
    $invoices = app(ArInvoiceService::class);
    $inv = $invoices->createDraft(1, $customer->id, [
        ['description' => 'Service', 'qty' => '1.000', 'unit_price_cents' => 10000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 10000],
    ], $user->id);
    $inv = $invoices->issue($inv, $user->id);

    /** @var ArAllocationService $alloc */
    $alloc = app(ArAllocationService::class);

    expect(fn () => $alloc->createPaymentAndAllocate([
        'invoice_id' => $inv->id,
        'amount_cents' => 11000,
        'method' => 'bank',
    ], $user->id))->toThrow(ValidationException::class);
});


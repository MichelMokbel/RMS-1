<?php

use App\Models\ArInvoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('manager', 'web');
});

it('shows cash in receivables print when invoice allocations are cash', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $invoice = ArInvoice::factory()->create([
        'status' => 'issued',
        'type' => 'invoice',
        'payment_type' => 'credit',
        'issue_date' => now()->toDateString(),
        'total_cents' => 10000,
        'paid_total_cents' => 6000,
        'balance_cents' => 4000,
    ]);

    $payment = Payment::factory()->create([
        'customer_id' => $invoice->customer_id,
        'source' => 'ar',
        'method' => 'cash',
        'amount_cents' => 10000,
    ]);

    PaymentAllocation::query()->create([
        'payment_id' => $payment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $invoice->id,
        'amount_cents' => 10000,
    ]);

    $this->actingAs($user)
        ->get(route('reports.receivables.print').'?status=issued')
        ->assertOk()
        ->assertSee($invoice->customer?->name ?? '');
});

it('shows mixed in receivables print when invoice has both cash and card allocations', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $invoice = ArInvoice::factory()->create([
        'status' => 'partially_paid',
        'type' => 'invoice',
        'payment_type' => 'credit',
        'issue_date' => now()->toDateString(),
        'total_cents' => 10000,
        'paid_total_cents' => 6000,
        'balance_cents' => 4000,
    ]);

    $cashPayment = Payment::factory()->create([
        'customer_id' => $invoice->customer_id,
        'source' => 'ar',
        'method' => 'cash',
        'amount_cents' => 4000,
    ]);

    $cardPayment = Payment::factory()->create([
        'customer_id' => $invoice->customer_id,
        'source' => 'ar',
        'method' => 'card',
        'amount_cents' => 6000,
    ]);

    PaymentAllocation::query()->create([
        'payment_id' => $cashPayment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $invoice->id,
        'amount_cents' => 4000,
    ]);

    PaymentAllocation::query()->create([
        'payment_id' => $cardPayment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $invoice->id,
        'amount_cents' => 6000,
    ]);

    $this->actingAs($user)
        ->get(route('reports.receivables.print').'?status=partially_paid')
        ->assertOk()
        ->assertSee($invoice->customer?->name ?? '');
});

it('filters receivables print by allocation-derived payment type', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('manager');

    $cashInvoice = ArInvoice::factory()->create([
        'status' => 'issued',
        'invoice_number' => 'CASH-FLT-001',
        'type' => 'invoice',
        'payment_type' => 'credit',
        'issue_date' => now()->toDateString(),
        'total_cents' => 5000,
        'paid_total_cents' => 2000,
        'balance_cents' => 3000,
    ]);
    $cardInvoice = ArInvoice::factory()->create([
        'status' => 'issued',
        'invoice_number' => 'CARD-FLT-001',
        'type' => 'invoice',
        'payment_type' => 'credit',
        'issue_date' => now()->toDateString(),
        'total_cents' => 5000,
        'paid_total_cents' => 2000,
        'balance_cents' => 3000,
    ]);

    $cashPayment = Payment::factory()->create([
        'customer_id' => $cashInvoice->customer_id,
        'source' => 'ar',
        'method' => 'cash',
        'amount_cents' => 5000,
    ]);
    $cardPayment = Payment::factory()->create([
        'customer_id' => $cardInvoice->customer_id,
        'source' => 'ar',
        'method' => 'card',
        'amount_cents' => 5000,
    ]);

    PaymentAllocation::query()->create([
        'payment_id' => $cashPayment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $cashInvoice->id,
        'amount_cents' => 5000,
    ]);
    PaymentAllocation::query()->create([
        'payment_id' => $cardPayment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $cardInvoice->id,
        'amount_cents' => 5000,
    ]);

    $response = $this->actingAs($user)
        ->get(route('reports.receivables.print').'?status=issued&payment_type=cash')
        ->assertOk();

    $response
        ->assertSee($cashInvoice->customer?->name ?? '')
        ->assertDontSee($cardInvoice->customer?->name ?? '');
});

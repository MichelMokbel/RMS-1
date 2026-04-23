<?php

use App\Models\AccountingCompany;
use App\Models\AccountingPeriod;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\AR\ArInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('manager');
    $this->user = User::factory()->create(['status' => 'active']);
    $this->user->assignRole('admin');
});

it('throws in production when accounting_periods table is absent', function () {
    // Temporarily set app environment to production.
    $original = app()->environment();
    app()->detectEnvironment(fn () => 'production');

    // Simulate missing table by spying on Schema.
    Schema::shouldReceive('hasTable')->with('accounting_periods')->andReturn(false);
    Schema::shouldReceive('hasTable')->andReturn(true);

    $gate = app(AccountingPeriodGateService::class);

    try {
        expect(fn () => $gate->assertDateOpen(now()->toDateString(), null, null, 'all', 'period'))
            ->toThrow(ValidationException::class);
    } finally {
        app()->detectEnvironment(fn () => $original);
        // Reset the Schema facade mock so subsequent tests use the real Schema.
        Schema::clearResolvedInstances();
        \Mockery::close();
    }
});

it('returns silently in non-production when accounting_periods table is absent', function () {
    Schema::shouldReceive('hasTable')->with('accounting_periods')->andReturn(false);
    Schema::shouldReceive('hasTable')->andReturn(true);

    app()->detectEnvironment(fn () => 'testing');

    $gate = app(AccountingPeriodGateService::class);

    try {
        // Should not throw — bypass is allowed in non-production.
        $gate->assertDateOpen(now()->toDateString(), null, null, 'all', 'period');
        expect(true)->toBeTrue();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
        Schema::clearResolvedInstances();
        \Mockery::close();
    }
});

it('blocks AR invoice issue when the accounting period is closed', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();

    // Update the first available open period to cover today so the gate finds it, then close it.
    $period = AccountingPeriod::query()
        ->where('company_id', $company->id)
        ->orderBy('period_number')
        ->firstOrFail();

    $period->update([
        'start_date' => now()->startOfMonth()->toDateString(),
        'end_date' => now()->endOfMonth()->toDateString(),
        'status' => 'closed',
    ]);

    $customer = Customer::factory()->corporate()->create();

    /** @var ArInvoiceService $svc */
    $svc = app(ArInvoiceService::class);

    $invoice = $svc->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Service', 'qty' => '1.000', 'unit_price_cents' => 10000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 10000],
        ],
        actorId: $this->user->id,
    );

    expect(fn () => $svc->issue($invoice, $this->user->id))
        ->toThrow(ValidationException::class);

    // Invoice must remain in draft — the period gate must fire before status mutation.
    expect(ArInvoice::find($invoice->id)->status)->toBe('draft');
});

it('allows AR invoice issue when the accounting period is open', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();

    $period = AccountingPeriod::query()
        ->where('company_id', $company->id)
        ->orderBy('period_number')
        ->firstOrFail();

    $period->update([
        'start_date' => now()->startOfMonth()->toDateString(),
        'end_date' => now()->endOfMonth()->toDateString(),
        'status' => 'open',
    ]);

    $customer = Customer::factory()->corporate()->create();

    /** @var ArInvoiceService $svc */
    $svc = app(ArInvoiceService::class);

    $invoice = $svc->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Service', 'qty' => '1.000', 'unit_price_cents' => 5000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 5000],
        ],
        actorId: $this->user->id,
    );

    $issued = $svc->issue($invoice, $this->user->id);

    expect($issued->status)->toBe('issued');
});

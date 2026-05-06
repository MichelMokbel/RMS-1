<?php

use App\Models\AccountingCompany;
use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function makeCustomerPaymentsManager(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::firstOrCreate(['name' => 'manager'], ['guard_name' => 'web']);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function extractCustomerPaymentsSheetXml(string $binary): string
{
    $path = tempnam(sys_get_temp_dir(), 'customer-payments-test-');
    file_put_contents($path, $binary);

    $zip = new \ZipArchive();
    $opened = $zip->open($path);
    expect($opened)->toBeTrue();

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');

    $zip->close();
    @unlink($path);

    return (string) $sheetXml;
}

it('shows customer payments in the accounts reports list', function () {
    $user = makeCustomerPaymentsManager();

    $this->actingAs($user)
        ->get(route('reports.index', ['category' => 'accounts']))
        ->assertOk()
        ->assertSee('Customer Payments');
});

it('shows all ar customer payments including allocated ones and excludes non-ar or voided rows', function () {
    $user = makeCustomerPaymentsManager();
    $customer = Customer::factory()->create(['name' => 'Payment Customer']);
    $defaultCompanyId = app(AccountingContextService::class)->defaultCompanyId();

    $openPayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'company_id' => $defaultCompanyId,
        'source' => 'ar',
        'amount_cents' => 15000,
        'reference' => 'AR-OPEN',
        'received_at' => now()->toDateTimeString(),
    ]);

    $allocatedPayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'company_id' => $defaultCompanyId,
        'source' => 'ar',
        'amount_cents' => 12000,
        'reference' => 'AR-ALLOCATED',
        'received_at' => now()->subDay()->toDateTimeString(),
    ]);

    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'status' => 'issued',
        'total_cents' => 12000,
        'balance_cents' => 12000,
    ]);

    PaymentAllocation::query()->create([
        'payment_id' => $allocatedPayment->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $invoice->id,
        'amount_cents' => 12000,
        'allocated_at' => now(),
        'created_by' => $user->id,
        'voided_at' => null,
        'voided_by' => null,
        'void_reason' => null,
    ]);

    $posPayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'company_id' => $defaultCompanyId,
        'source' => 'pos',
        'amount_cents' => 18000,
        'reference' => 'POS-HIDDEN',
        'received_at' => now()->subDays(2)->toDateTimeString(),
    ]);

    $voidedPayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'company_id' => $defaultCompanyId,
        'source' => 'ar',
        'amount_cents' => 9000,
        'reference' => 'AR-VOIDED',
        'received_at' => now()->subDays(3)->toDateTimeString(),
        'voided_at' => now(),
        'voided_by' => $user->id,
        'void_reason' => 'Voided',
    ]);

    $this->actingAs($user)
        ->get(route('reports.customer-payments'))
        ->assertOk()
        ->assertSee('Customer Payments')
        ->assertSee('Payment Customer')
        ->assertSee('#'.$openPayment->id)
        ->assertSee('#'.$allocatedPayment->id)
        ->assertDontSee('#'.$posPayment->id)
        ->assertDontSee('#'.$voidedPayment->id)
        ->assertDontSee('No customer payments found.');
});

it('filters customer payments by accounting company on screen and excel export', function () {
    $user = makeCustomerPaymentsManager();

    $companyA = AccountingCompany::query()->create([
        'name' => 'Company A',
        'code' => 'COMP-A',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => true,
    ]);

    $companyB = AccountingCompany::query()->create([
        'name' => 'Company B',
        'code' => 'COMP-B',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => false,
    ]);

    $customer = Customer::factory()->create(['name' => 'Scoped Payment Customer']);

    $visible = Payment::factory()->create([
        'customer_id' => $customer->id,
        'source' => 'ar',
        'company_id' => $companyB->id,
        'amount_cents' => 15000,
        'reference' => 'B-PAYMENT',
        'received_at' => now()->toDateTimeString(),
    ]);

    $hidden = Payment::factory()->create([
        'customer_id' => $customer->id,
        'source' => 'ar',
        'company_id' => $companyA->id,
        'amount_cents' => 9000,
        'reference' => 'A-PAYMENT',
        'received_at' => now()->subDay()->toDateTimeString(),
    ]);

    $this->actingAs($user)
        ->get(route('reports.customer-payments', ['company_id' => $companyB->id]))
        ->assertOk()
        ->assertSee('#'.$visible->id)
        ->assertDontSee('#'.$hidden->id);

    $response = $this->actingAs($user)
        ->get(route('reports.customer-payments.xlsx', ['company_id' => $companyB->id]));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $sheetXml = extractCustomerPaymentsSheetXml(file_get_contents($response->getFile()->getPathname()));

    expect($sheetXml)->toContain((string) $visible->id)
        ->toContain('B-PAYMENT')
        ->not->toContain((string) $hidden->id)
        ->not->toContain('A-PAYMENT');
});

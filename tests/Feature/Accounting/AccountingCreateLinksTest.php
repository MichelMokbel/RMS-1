<?php

use App\Models\ApChequeClearance;
use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\ArClearingSettlement;
use App\Models\BankAccount;
use App\Models\GlBatch;
use App\Models\User;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('offers a new AP document or payment from the corresponding detail page only to finance writers', function (bool $canWrite) {
    Role::findOrCreate('staff');
    Permission::findOrCreate('accounting.write');
    Permission::findOrCreate('finance.access');
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('staff');
    $user->givePermissionTo('finance.access');
    if ($canWrite) {
        $user->givePermissionTo('accounting.write');
    }
    $this->actingAs($user);

    $invoice = Volt::test('payables.invoices.show', ['invoice' => ApInvoice::factory()->create()]);
    $payment = Volt::test('payables.payments.show', ['payment' => ApPayment::factory()->create()]);

    if ($canWrite) {
        $invoice->assertSee('Create New Document')->assertSeeHtml('href="'.route('payables.create').'"');
        $payment->assertSee('Create New Payment')->assertSeeHtml('href="'.route('payables.payments.create').'"');
    } else {
        $invoice->assertDontSee('Create New Document');
        $payment->assertDontSee('Create New Payment');
    }
})->with([true, false]);

it('offers new settlement and clearance links only to finance writers', function (bool $canWrite) {
    Role::findOrCreate('staff');
    Permission::findOrCreate('accounting.write');
    Permission::findOrCreate('finance.access');
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('staff');
    $user->givePermissionTo('finance.access');
    if ($canWrite) {
        $user->givePermissionTo('accounting.write');
    }
    $this->actingAs($user);

    $bank = BankAccount::factory()->create();
    $settlement = ArClearingSettlement::create([
        'company_id' => $bank->company_id,
        'bank_account_id' => $bank->id,
        'settlement_method' => 'card',
        'settlement_date' => now()->toDateString(),
        'amount_cents' => 10000,
        'created_by' => $user->id,
    ]);
    $payment = ApPayment::factory()->create(['company_id' => $bank->company_id, 'payment_method' => 'cheque']);
    $clearance = ApChequeClearance::create([
        'company_id' => $bank->company_id,
        'bank_account_id' => $bank->id,
        'ap_payment_id' => $payment->id,
        'clearance_date' => now()->toDateString(),
        'amount' => $payment->amount,
        'created_by' => $user->id,
    ]);

    $settlementPage = Volt::test('accounting.ar-clearing-show', ['settlement' => $settlement]);
    $clearancePage = Volt::test('accounting.ap-cheque-clearance-show', ['clearance' => $clearance]);

    if ($canWrite) {
        $settlementPage->assertSee('Create New Settlement')->assertSeeHtml('href="'.route('accounting.ar-clearing').'"');
        $clearancePage->assertSee('Create New Clearance')->assertSeeHtml('href="'.route('accounting.ap-cheque-clearance').'"');
    } else {
        $settlementPage->assertDontSee('Create New Settlement');
        $clearancePage->assertDontSee('Create New Clearance');
    }
})->with([true, false]);

it('offers a new GL batch to actors authorized by the existing batch workflow', function (string $access) {
    Role::findOrCreate('admin');
    Role::findOrCreate('manager');
    Permission::findOrCreate('finance.access');
    $user = User::factory()->create(['status' => 'active']);
    if ($access === 'finance.access') {
        $user->givePermissionTo('finance.access');
    } elseif ($access !== 'none') {
        $user->assignRole($access);
    }
    $this->actingAs($user);
    $batch = GlBatch::create([
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'status' => 'open',
    ]);

    $page = Volt::test('ledger.batches.show', ['batch' => $batch]);
    if ($access === 'none') {
        $page->assertForbidden();
        Volt::test('ledger.batches.index')->assertForbidden();
    } else {
        $page->assertSee('Create New Batch')->assertSeeHtml('href="'.route('ledger.batches.index').'"');
        Volt::test('ledger.batches.index')->assertSee('Generate');
    }
})->with(['admin', 'manager', 'finance.access', 'none']);

<?php

use App\Models\AccountingCompany;
use App\Models\AccountingAccountMapping;
use App\Models\BankAccount;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
});

it('renders the accounting setup page for admins', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/settings/accounting')
        ->assertOk()
        ->assertSee('Accounting Setup')
        ->assertSee('Chart of Accounts')
        ->assertSee('Posting Mappings')
        ->assertSee('Bank & Settlement Accounts');
});

it('bootstraps accounting mappings and linked bank ledger accounts for the selected company', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $this->actingAs($user)
        ->get('/settings/accounting?tab=mappings')
        ->assertOk()
        ->assertSee('Posting Mappings');

    expect(AccountingAccountMapping::query()
        ->where('company_id', $company->id)
        ->where('mapping_key', 'expense_default')
        ->exists())->toBeTrue();

    $bank = BankAccount::query()->where('company_id', $company->id)->where('is_default', true)->firstOrFail();
    $operatingLedger = LedgerAccount::query()->where('code', '1010')->firstOrFail();

    expect((int) $bank->ledger_account_id)->toBe((int) $operatingLedger->id);
});

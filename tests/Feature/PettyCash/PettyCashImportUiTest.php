<?php

use App\Models\AccountingCompany;
use App\Models\ExpenseCategory;
use App\Models\PettyCashImportBatch;
use App\Models\PettyCashImportInvoice;
use App\Models\PettyCashImportRow;
use App\Models\PettyCashWallet;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PettyCash\PettyCashImportTemplateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Permission::findOrCreate('petty_cash.import');
    Role::findOrCreate('admin');
    Role::findOrCreate('manager');
});

it('shows the petty cash import entry point only to users with import permission', function () {
    $importer = User::factory()->create();
    $importer->assignRole('admin');
    $importer->givePermissionTo('petty_cash.import');
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $manager->givePermissionTo('petty_cash.import');

    $this->actingAs($importer)
        ->get(route('petty-cash.index'))
        ->assertOk()
        ->assertSee('Import Expenses')
        ->assertSee(route('petty-cash.imports.index'), false);

    $this->actingAs($importer)
        ->get(route('petty-cash.imports.index'))
        ->assertOk()
        ->assertSee('Daily Expense Imports')
        ->assertSee('Business Date')
        ->assertSee('Default Category')
        ->assertSee('Default Wallet')
        ->assertSee('Validate and Stage');

    $this->actingAs($manager)
        ->get(route('petty-cash.index'))
        ->assertOk()
        ->assertDontSee('Import Expenses')
        ->assertDontSee(route('petty-cash.imports.index'), false);
});

it('protects all petty cash import routes with the dedicated permission', function () {
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $manager->givePermissionTo('petty_cash.import');

    $this->actingAs($manager)->get(route('petty-cash.imports.index'))->assertForbidden();
    $this->actingAs($manager)->get(route('petty-cash.imports.template'))->assertForbidden();
    $this->actingAs($manager)->get(route('petty-cash.imports.show', ['batch' => 999]))->assertForbidden();
});

it('downloads a controlled petty cash workbook with exact headers and lookup validations', function () {
    $company = AccountingCompany::query()->where('is_default', true)->first();
    if (! $company) {
        $company = AccountingCompany::query()->create([
            'name' => 'Default Company',
            'code' => 'DEFAULT',
            'base_currency' => 'QAR',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    $user = User::factory()->create();
    $user->assignRole('admin');
    $user->givePermissionTo('petty_cash.import');
    $supplier = Supplier::factory()->create(['company_id' => $company->id, 'name' => 'Daily Supplier', 'status' => 'active']);
    $heldSupplier = Supplier::factory()->create(['company_id' => $company->id, 'name' => 'Held Supplier', 'status' => 'active', 'hold_status' => 'hold']);
    $category = ExpenseCategory::factory()->create(['name' => 'Daily Purchases', 'active' => true]);
    $wallet = PettyCashWallet::factory()->create(['driver_name' => 'Main Custodian', 'active' => true]);

    $response = $this->actingAs($user)->get(route('petty-cash.imports.template'));
    $response->assertOk()->assertDownload('petty-cash-daily-import-template.xlsx');

    $path = $response->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    try {
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $expenses = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $suppliers = (string) $zip->getFromName('xl/worksheets/sheet3.xml');
        $categories = (string) $zip->getFromName('xl/worksheets/sheet4.xml');
        $wallets = (string) $zip->getFromName('xl/worksheets/sheet5.xml');

        preg_match_all('/<c r="[A-L]1"[^>]*><is><t[^>]*>([^<]+)<\/t><\/is><\/c>/', $expenses, $matches);
        $headers = array_map(fn (string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8'), $matches[1]);

        expect($headers)->toBe(PettyCashImportTemplateBuilder::HEADERS)
            ->and($workbook)->toContain('<sheet name="Suppliers" sheetId="3" state="veryHidden"')
            ->and($workbook)->toContain('<sheet name="Categories" sheetId="4" state="veryHidden"')
            ->and($workbook)->toContain('<sheet name="Wallets" sheetId="5" state="veryHidden"')
            ->and($expenses)->toContain('sqref="B2:B5001"')
            ->and($expenses)->toContain('<formula1>SupplierValues</formula1>')
            ->and($expenses)->toContain('sqref="G2:G5001"')
            ->and($expenses)->toContain('sqref="I2:I5001"')
            ->and($expenses)->toMatch('/type="list" allowBlank="0"[^>]+sqref="B2:B5001"/')
            ->and($expenses)->toMatch('/type="list" allowBlank="0"[^>]+sqref="G2:G5001"/')
            ->and($expenses)->toMatch('/type="decimal" operator="greaterThan" allowBlank="1"[^>]+sqref="I2:I5001"/')
            ->and($suppliers)->toContain($supplier->id.' | Daily Supplier')
            ->and($suppliers)->not->toContain($heldSupplier->id.' | Held Supplier')
            ->and($categories)->toContain($category->id.' | Daily Purchases')
            ->and($wallets)->toContain($wallet->id.' | Main Custodian');
    } finally {
        $zip->close();
    }
});

it('reviews staged invoice groups with totals and line-level errors before commit', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $user = User::factory()->create();
    $user->assignRole('admin');
    $user->givePermissionTo('petty_cash.import');
    $supplier = Supplier::factory()->create(['company_id' => $company->id, 'name' => 'Review Supplier']);
    $category = ExpenseCategory::factory()->create(['name' => 'Review Category', 'active' => true]);
    $wallet = PettyCashWallet::factory()->create(['driver_name' => 'Review Wallet', 'active' => true]);
    $batch = PettyCashImportBatch::query()->create([
        'company_id' => $company->id,
        'business_date' => '2026-08-15',
        'status' => 'ready',
        'source_name' => 'daily-expenses.xlsx',
        'storage_disk' => 'local',
        'object_key' => 'petty-cash/imports/daily-expenses.xlsx',
        'sha256' => str_repeat('a', 64),
        'idempotency_key' => str_repeat('b', 64),
        'stats' => ['rows' => 1, 'invoices' => 1, 'valid_invoices' => 1, 'invalid_invoices' => 0],
        'initiated_by' => $user->id,
        'initiated_at' => now(),
    ]);
    $stagedInvoice = PettyCashImportInvoice::query()->create([
        'import_batch_id' => $batch->id,
        'entry_id' => 'ENTRY-001',
        'group_key' => hash('sha256', 'ENTRY-001'),
        'status' => 'valid',
        'header' => [
            'supplier_id' => $supplier->id,
            'reference_number' => 'SUP-REF-100',
            'due_date' => '2026-08-15',
            'category_id' => $category->id,
            'wallet_id' => $wallet->id,
            'paid' => true,
            'tax_amount' => 3,
        ],
        'errors' => [],
        'client_uuid' => fake()->uuid(),
    ]);
    PettyCashImportRow::query()->create([
        'import_batch_id' => $batch->id,
        'import_invoice_id' => $stagedInvoice->id,
        'row_number' => 2,
        'source_identifier' => 'ENTRY-001',
        'status' => 'valid',
        'payload' => [
            'entry_id' => 'ENTRY-001',
            'supplier' => $supplier->id.' | '.$supplier->name,
            'reference_number' => 'SUP-REF-100',
            'due_date' => '2026-08-15',
            'category' => $category->id.' | '.$category->name,
            'wallet' => $wallet->id.' | '.$wallet->driver_name,
            'paid' => true,
            'description' => 'Daily supplies',
            'quantity' => 2,
            'unit_price' => 10,
            'tax_amount' => 3,
            '_sheet_row' => 2,
        ],
        'errors' => [],
        'row_hash' => hash('sha256', 'ENTRY-001-row-2'),
    ]);

    $this->actingAs($user)
        ->get(route('petty-cash.imports.show', ['batch' => $batch->id]))
        ->assertOk()
        ->assertSee('Invoice Group Summary')
        ->assertSee('Review Supplier')
        ->assertSee('Review Category')
        ->assertSee('Review Wallet')
        ->assertSee('SUP-REF-100')
        ->assertSee('23.00')
        ->assertSee('Confirm and Commit')
        ->assertSee('No accounting or wallet records have changed yet.');
});

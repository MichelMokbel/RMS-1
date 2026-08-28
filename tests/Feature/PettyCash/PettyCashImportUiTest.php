<?php

use App\Models\AccountingCompany;
use App\Models\BankAccount;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Models\PettyCashImportBatch;
use App\Models\PettyCashImportCategoryProposal;
use App\Models\PettyCashImportInvoice;
use App\Models\PettyCashImportRow;
use App\Models\PettyCashWallet;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PettyCash\PettyCashImportEditor;
use App\Services\PettyCash\PettyCashImportService;
use App\Services\PettyCash\PettyCashImportTemplateBuilder;
use App\Support\Imports\SafeSpreadsheetReader;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
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
        ->assertSee('Expense Imports')
        ->assertSee('Daily')
        ->assertSee('Multiple Dates')
        ->assertSee('Business Date')
        ->assertSee('Default Category')
        ->assertSee('Pay From')
        ->assertSee('Bank Account')
        ->assertSee('Validate and Stage');

    $this->actingAs($manager)
        ->get(route('petty-cash.index'))
        ->assertOk()
        ->assertDontSee('Import Expenses')
        ->assertDontSee(route('petty-cash.imports.index'), false);
});

it('shows required multiple-date defaults and downloads the bulk template', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $user = User::factory()->create();
    $user->assignRole('admin');
    $user->givePermissionTo('petty_cash.import');
    Supplier::factory()->create(['company_id' => $company->id, 'name' => 'Bulk Supplier', 'status' => 'active']);
    PettyCashWallet::factory()->create(['driver_name' => 'Bulk Wallet', 'active' => true]);
    $bankLedger = LedgerAccount::query()->create([
        'company_id' => $company->id,
        'code' => 'UI-IMPORT-BANK',
        'name' => 'UI Import Bank Ledger',
        'type' => 'asset',
        'account_class' => 'asset',
        'allow_direct_posting' => true,
        'is_active' => true,
    ]);
    BankAccount::query()->create([
        'company_id' => $company->id,
        'ledger_account_id' => $bankLedger->id,
        'name' => 'Main Bank',
        'code' => 'MAIN-BANK',
        'account_type' => 'checking',
        'currency_code' => $company->base_currency,
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->actingAs($user);

    Volt::test('petty-cash.imports.index')
        ->set('import_mode', 'bulk')
        ->assertSee('Upload multiple-date workbook')
        ->assertSee('Default Supplier')
        ->assertSee('Default Paid Status')
        ->assertSee('Main Bank')
        ->assertSee('Paid expenses will create bank-transfer payments')
        ->assertDontSee('Choose default wallet')
        ->assertDontSee('Default Category');

    Volt::test('petty-cash.imports.index')
        ->set('import_mode', 'bulk')
        ->call('stage')
        ->assertHasErrors(['default_supplier_id', 'default_paid', 'workbook'])
        ->assertHasNoErrors(['default_wallet_id', 'default_bank_account_id']);

    $response = $this->get(route('petty-cash.imports.template', ['mode' => 'bulk']));
    $response->assertOk()->assertDownload('petty-cash-multiple-date-import-template.xlsx');
    $path = $response->baseResponse->getFile()->getPathname();
    $parsed = app(SafeSpreadsheetReader::class)->workbook($path);

    expect(array_keys($parsed['sheets']))
        ->toContain('petty_cash_expenses', 'category_definitions')
        ->and(array_keys($parsed['sheets']['petty_cash_expenses'][0]))->toBe(PettyCashImportTemplateBuilder::BULK_HEADERS);
});

function pettyCashImportReviewFixture(): array
{
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $user = User::factory()->create();
    $user->assignRole('admin');
    $user->givePermissionTo('petty_cash.import');
    $supplier = Supplier::factory()->create(['company_id' => $company->id, 'name' => 'Editable Supplier', 'status' => 'active']);
    $wallet = PettyCashWallet::factory()->create(['driver_name' => 'Editable Wallet', 'active' => true]);
    $batch = PettyCashImportBatch::query()->create([
        'company_id' => $company->id,
        'import_mode' => 'bulk',
        'business_date' => '2026-07-01',
        'date_from' => '2026-07-01',
        'date_to' => '2026-08-31',
        'default_supplier_id' => $supplier->id,
        'default_wallet_id' => $wallet->id,
        'default_paid' => true,
        'revision' => 0,
        'status' => 'needs_review',
        'source_name' => 'bulk-expenses.xlsx',
        'storage_disk' => 'local',
        'object_key' => 'petty-cash/imports/bulk-expenses.xlsx',
        'sha256' => str_repeat('a', 64),
        'idempotency_key' => str_repeat('b', 64),
        'stats' => ['rows' => 1, 'invoices' => 1, 'valid_invoices' => 0, 'invalid_invoices' => 1, 'invalid_categories' => 1],
        'initiated_by' => $user->id,
        'initiated_at' => now(),
    ]);
    $invoice = PettyCashImportInvoice::query()->create([
        'import_batch_id' => $batch->id,
        'entry_id' => '20260701-RAW',
        'business_date' => '2026-07-01',
        'group_key' => hash('sha256', '2026-07-01|20260701-RAW'),
        'status' => 'invalid',
        'excluded' => false,
        'header' => [
            'supplier_id' => $supplier->id, 'category' => 'Raw Material', 'wallet_id' => $wallet->id,
            'paid' => true, 'paid_requested' => true, 'due_date' => '2026-07-01',
        ],
        'errors' => ['category' => 'A new category will be created during commit.'],
        'client_uuid' => fake()->uuid(),
    ]);
    PettyCashImportRow::query()->create([
        'import_batch_id' => $batch->id,
        'import_invoice_id' => $invoice->id,
        'row_number' => 2,
        'source_identifier' => '20260701-RAW',
        'status' => 'invalid',
        'excluded' => false,
        'payload' => [
            'business_date' => '2026-07-01', 'entry_id' => '20260701-RAW',
            'supplier' => (string) $supplier->id, 'category' => 'Raw Material', 'wallet' => (string) $wallet->id,
            'paid' => true, 'description' => 'Meat', 'quantity' => 1, 'unit_price' => 120,
        ],
        'errors' => ['category' => ['A new category will be created during commit.']],
        'row_hash' => hash('sha256', 'bulk-row'),
    ]);
    $proposal = PettyCashImportCategoryProposal::query()->create([
        'import_batch_id' => $batch->id,
        'source_code' => '1',
        'source_name' => 'Raw Material',
        'normalized_name' => 'raw material',
        'is_declared' => true,
        'status' => 'proposed',
    ]);

    return [$user, $batch, $proposal];
}

it('renders accessible category review and editable bulk invoice and line controls before commit', function () {
    [$user, $batch] = pettyCashImportReviewFixture();

    $this->actingAs($user)
        ->get(route('petty-cash.imports.show', $batch))
        ->assertOk()
        ->assertSee('Multiple Dates:')
        ->assertSee('Review Filters and Overrides')
        ->assertSee('Review Categories')
        ->assertSee('id="category-review"', false)
        ->assertSee('href="#category-review"', false)
        ->assertSee('Save Category')
        ->assertSee('Use existing category')
        ->assertSee('Create a new category')
        ->assertSee('Will create')
        ->assertSee('Apply to Filtered Invoices')
        ->assertSee('Add Staged Expense')
        ->assertSee('Save Invoice')
        ->assertSee('Add Line')
        ->assertSee('Exclude Invoice')
        ->assertSee('Meat')
        ->assertDontSee('Confirm and Commit');
});

it('validates category choices and saves existing and new category resolutions from the review page', function () {
    [$user, $batch, $proposal] = pettyCashImportReviewFixture();
    $category = ExpenseCategory::factory()->create(['name' => 'Selected Supplies', 'active' => true]);
    $this->actingAs($user);
    $editor = Mockery::mock(PettyCashImportEditor::class);
    $editor->shouldReceive('resolveCategory')->once()
        ->withArgs(fn (PettyCashImportCategoryProposal $received, int $revision, array $data, User $actor): bool => $received->is($proposal) && $revision === 0 && $data === ['category_id' => $category->id] && $actor->is($user))
        ->andReturnUsing(function () use ($proposal, $category, $batch) {
            $proposal->update(['status' => 'mapped', 'expense_category_id' => $category->id]);
            $batch->increment('revision');

            return $batch;
        });
    $editor->shouldReceive('resolveCategory')->once()
        ->withArgs(fn (PettyCashImportCategoryProposal $received, int $revision, array $data, User $actor): bool => $received->is($proposal) && $revision === 1 && $data === ['name' => 'Reviewed Supplies'] && $actor->is($user))
        ->andReturn($batch);
    app()->instance(PettyCashImportEditor::class, $editor);

    $component = Volt::test('petty-cash.imports.show', ['batch' => $batch->id])
        ->set("categoryForms.{$proposal->id}.mode", 'existing')
        ->set("categoryForms.{$proposal->id}.category_id", '')
        ->assertSee('Existing category')
        ->assertSee('<select', false)
        ->call('saveCategory', $proposal->id)
        ->assertHasErrors(["categoryForms.{$proposal->id}.category_id" => 'required'])
        ->set("categoryForms.{$proposal->id}.category_id", (string) $category->id)
        ->call('saveCategory', $proposal->id)
        ->assertHasNoErrors()
        ->assertSee('Selected')
        ->assertSet("categoryForms.{$proposal->id}.category_id", (string) $category->id);

    $component->set("categoryForms.{$proposal->id}.mode", 'new')
        ->set("categoryForms.{$proposal->id}.name", '')
        ->assertSee('New category name')
        ->call('saveCategory', $proposal->id)
        ->assertHasErrors(["categoryForms.{$proposal->id}.name" => 'required'])
        ->set("categoryForms.{$proposal->id}.name", 'Reviewed Supplies')
        ->call('saveCategory', $proposal->id)
        ->assertHasNoErrors();
});

it('scopes category review actions to the current batch and hides editing after completion', function () {
    [$user, $batch, $proposal] = pettyCashImportReviewFixture();
    $otherBatch = $batch->replicate();
    $otherBatch->forceFill(['idempotency_key' => str_repeat('c', 64)])->save();
    $otherProposal = $proposal->replicate();
    $otherProposal->forceFill(['import_batch_id' => $otherBatch->id])->save();
    $this->actingAs($user);
    $editor = Mockery::mock(PettyCashImportEditor::class);
    $editor->shouldNotReceive('resolveCategory');
    app()->instance(PettyCashImportEditor::class, $editor);

    foreach ([$otherProposal->id, $otherProposal->id + 1] as $proposalId) {
        expect(fn () => Volt::test('petty-cash.imports.show', ['batch' => $batch->id])
            ->call('saveCategory', $proposalId))->toThrow(ModelNotFoundException::class);
    }

    $batch->update(['status' => 'completed']);
    $this->get(route('petty-cash.imports.show', $batch))
        ->assertOk()
        ->assertDontSee('Save Category')
        ->assertDontSee('Create a new category')
        ->assertDontSee('Save Invoice');
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
    $parsed = app(SafeSpreadsheetReader::class)->workbook($path);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    try {
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $expenses = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $styles = (string) $zip->getFromName('xl/styles.xml');
        $suppliers = (string) $zip->getFromName('xl/worksheets/sheet3.xml');
        $categories = (string) $zip->getFromName('xl/worksheets/sheet4.xml');
        $wallets = (string) $zip->getFromName('xl/worksheets/sheet5.xml');

        preg_match_all('/<c r="[A-K]1"[^>]*><is><t[^>]*>([^<]+)<\/t><\/is><\/c>/', $expenses, $matches);
        $headers = array_map(fn (string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8'), $matches[1]);

        expect($headers)->toBe(PettyCashImportTemplateBuilder::HEADERS)
            ->and($parsed['sheets']['petty_cash_expenses'])->toHaveCount(200)
            ->and(array_column(array_slice($parsed['sheets']['petty_cash_expenses'], 0, 4), 'entry_id'))->toBe(array_fill(0, 4, 'ENTRY-001'))
            ->and(array_column(array_slice($parsed['sheets']['petty_cash_expenses'], 4, 4), 'entry_id'))->toBe(array_fill(0, 4, 'ENTRY-002'))
            ->and($workbook)->toContain('<sheet name="Suppliers" sheetId="3" state="veryHidden"')
            ->and($workbook)->toContain('<sheet name="Categories" sheetId="4" state="veryHidden"')
            ->and($workbook)->toContain('<sheet name="Wallets" sheetId="5" state="veryHidden"')
            ->and($expenses)->not->toContain('<conditionalFormatting')
            ->and($styles)->not->toContain('<dxfs')
            ->and($styles)->toContain('<fills count="10">')
            ->and($styles)->toContain('<cellXfs count="19">')
            ->and($styles)->toContain('<fgColor rgb="FFD1D5DB"/>')
            ->and($expenses)->toContain('<c r="A2" t="inlineStr" s="8">')
            ->and($expenses)->toContain('<c r="D2" t="inlineStr" s="9">')
            ->and($expenses)->toContain('<c r="J2" t="inlineStr" s="11">')
            ->and($expenses)->toContain('<c r="A3" t="inlineStr" s="17">')
            ->and($expenses)->toContain('<c r="B3" t="inlineStr" s="12">')
            ->and($expenses)->toContain('<c r="H3" t="inlineStr" s="14">')
            ->and($expenses)->toContain('<c r="A7" t="inlineStr" s="18">')
            ->and($expenses)->toContain('sqref="B2 B6 B10')
            ->and($expenses)->toContain('B202:B5001"')
            ->and($expenses)->toContain('<formula1>SupplierValues</formula1>')
            ->and($expenses)->toContain('sqref="G2 G6 G10')
            ->and($expenses)->toContain('sqref="I2:I5001"')
            ->and($expenses)->not->toContain('sqref="K2:K5001"')
            ->and($expenses)->toMatch('/type="list" allowBlank="1"[^>]+sqref="B2 B6 B10/')
            ->and($expenses)->toMatch('/type="list" allowBlank="1"[^>]+sqref="G2 G6 G10/')
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
        'stats' => ['rows' => 1, 'invoices' => 1, 'valid_invoices' => 1, 'invalid_invoices' => 0, 'unpaid_for_insufficient_balance' => 1],
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
            'paid' => false,
            'paid_requested' => true,
            'settlement_warning' => 'The wallet balance is insufficient, so this invoice will be imported as unpaid.',
            'tax_amount' => 0,
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
            'paid' => false,
            'paid_requested' => true,
            'settlement_warning' => 'The wallet balance is insufficient, so this invoice will be imported as unpaid.',
            'description' => 'Daily supplies',
            'quantity' => 2,
            'unit_price' => 10,
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
        ->assertSee('20.00')
        ->assertDontSee('Tax')
        ->assertSee('1 invoice was changed to pending because its wallet balance was insufficient.')
        ->assertSee('The wallet balance is insufficient, so this invoice will be imported as unpaid.')
        ->assertSee('Confirm and Commit')
        ->assertSee('wire:click="commitImport"', false)
        ->assertDontSee('wire:confirm=', false)
        ->assertDontSee('commit-petty-cash-import')
        ->assertSee('No accounting or wallet records have changed yet.');

    $service = Mockery::mock(PettyCashImportService::class);
    $service->shouldReceive('commit')
        ->once()
        ->withArgs(fn (PettyCashImportBatch $received, User $actor): bool => $received->is($batch) && $actor->is($user))
        ->andReturn($batch);
    app()->instance(PettyCashImportService::class, $service);

    Volt::test('petty-cash.imports.show', ['batch' => $batch->id])
        ->call('commitImport')
        ->assertHasNoErrors();
});

<?php

use App\Models\AccountingAccountMapping;
use App\Models\AccountingCompany;
use App\Models\AccountingPeriod;
use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Models\PettyCashImportBatch;
use App\Models\PettyCashImportEditEvent;
use App\Models\PettyCashWallet;
use App\Models\SubledgerEntry;
use App\Models\SubledgerLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\DashboardCashActivityService;
use App\Services\AP\ApReportsService;
use App\Services\PettyCash\PettyCashImportEditor;
use App\Services\PettyCash\PettyCashImportService;
use App\Services\PettyCash\PettyCashImportTemplateBuilder;
use App\Services\Spend\SpendReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('petty-cash-import-test');
    config([
        'petty_cash.imports.disk' => 'petty-cash-import-test',
        'petty_cash.allow_negative_wallet_balance' => false,
    ]);

    Permission::findOrCreate('petty_cash.import');
    Role::findOrCreate('admin');
    $this->actor = User::factory()->create(['status' => 'active']);
    $this->actor->assignRole('admin');
    $this->actor->givePermissionTo('petty_cash.import');

    $this->company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $this->company->forceFill(['base_currency' => 'KWD'])->save();
    $this->expenseAccount = pettyCashImportMapping($this->company, 'expense_default', '6900', 'expense');
    pettyCashImportMapping($this->company, 'ap_control', '2100', 'liability');
    pettyCashImportMapping($this->company, 'petty_cash_asset', '1015', 'asset');

    $this->supplier = Supplier::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'Daily Market',
        'status' => 'active',
        'default_expense_account_id' => $this->expenseAccount->id,
    ]);
    $this->category = ExpenseCategory::factory()->create(['name' => 'Kitchen Supplies', 'active' => true]);
    $this->wallet = PettyCashWallet::factory()->create([
        'driver_name' => 'Main Wallet',
        'balance' => 1000,
        'active' => true,
    ]);
});

it('stages grouped lines with defaults and atomically commits paid and unpaid expenses', function () {
    $workbook = pettyCashImportWorkbook([
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'RCPT-001', '', '', '', 'TRUE', 'Rice', '', '10.00', 'Morning run'],
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'RCPT-001', '', '', '', 'TRUE', 'Oil', '2', '5.00', 'Morning run'],
        ['ENTRY-002', $this->supplier->id.' | Daily Market', 'RCPT-002', '2026-08-18', $this->category->id.' | Kitchen Supplies', $this->wallet->id.' | Main Wallet', 'FALSE', 'Napkins', '3', '4.00', ''],
    ]);

    $service = app(PettyCashImportService::class);
    $batch = $service->stage(
        $workbook,
        '2026-08-15',
        $this->category->id,
        $this->wallet->id,
        $this->company->id,
        $this->actor
    );

    expect($batch->status->value)->toBe('ready')
        ->and($batch->rows)->toHaveCount(3)
        ->and($batch->invoices)->toHaveCount(2)
        ->and($batch->stats['paid_invoices'])->toBe(1)
        ->and((float) $batch->stats['subtotal'])->toBe(32.0)
        ->and($batch->stats)->not->toHaveKey('tax_amount')
        ->and((float) $batch->stats['total_amount'])->toBe(32.0);

    $first = $batch->invoices->firstWhere('entry_id', 'ENTRY-001');
    expect($first->rows)->toHaveCount(2)
        ->and($first->header['due_date'])->toBe('2026-08-15')
        ->and($first->header['category_id'])->toBe($this->category->id)
        ->and($first->header['wallet_id'])->toBe($this->wallet->id)
        ->and((float) $first->rows->first()->payload['quantity'])->toBe(1.0);

    $encryptedPayload = DB::table('petty_cash_import_rows')->where('id', $batch->rows->first()->id)->value('payload');
    expect($encryptedPayload)->not->toContain('Rice');
    Storage::disk('petty-cash-import-test')->assertExists($batch->object_key);

    $replayed = $service->stage(
        $workbook,
        '2026-08-15',
        $this->category->id,
        $this->wallet->id,
        $this->company->id,
        $this->actor
    );
    expect($replayed->id)->toBe($batch->id);

    $committed = $service->commit($batch, $this->actor);
    expect($committed->status->value)->toBe('completed');

    $paid = ApInvoice::query()->where('reference_number', 'RCPT-001')->firstOrFail();
    $unpaid = ApInvoice::query()->where('reference_number', 'RCPT-002')->firstOrFail();
    expect($paid->invoice_number)->toMatch('/^EXP-20260815-\d{4}$/')
        ->and($paid->items)->toHaveCount(2)
        ->and($paid->status)->toBe('paid')
        ->and($paid->currency_code)->toBe('KWD')
        ->and($paid->invoice_date->format('Y-m-d'))->toBe('2026-08-15')
        ->and($paid->source_document_type)->toBe('petty_cash_expense_import')
        ->and($paid->source_document_id)->toBe($first->id)
        ->and($unpaid->status)->toBe('posted')
        ->and(ApPayment::query()->count())->toBe(1)
        ->and(ApPayment::query()->firstOrFail()->payment_date->format('Y-m-d'))->toBe('2026-08-15')
        ->and(ApPayment::query()->firstOrFail()->currency_code)->toBe('KWD')
        ->and(ApPayment::query()->firstOrFail()->client_uuid)->toBe($first->client_uuid)
        ->and((float) $this->wallet->fresh()->balance)->toBe(980.0);

    expect(SubledgerEntry::query()
        ->where('source_type', 'ap_invoice')
        ->whereIn('source_id', [$paid->id, $unpaid->id])
        ->whereDate('entry_date', '2026-08-15')
        ->count())->toBe(2);

    $report = app(SpendReportService::class)->collect([
        'source' => 'petty_cash',
        'date_from' => '2026-08-15',
        'date_to' => '2026-08-15',
    ]);
    expect($report)->toHaveCount(2)
        ->and(round($report->sum('amount'), 2))->toBe(32.0);

    $aging = app(ApReportsService::class)->agingSummary($this->supplier->id, '2026-08-15');
    expect(round(array_sum($aging), 2))->toBe(12.0)
        ->and(round($aging['current'], 2))->toBe(12.0);

    $this->actingAs($this->actor)
        ->get(route('reports.supplier-statement.print', [
            'supplier_id' => $this->supplier->id,
            'date_from' => '2026-08-15',
            'date_to' => '2026-08-15',
        ]))
        ->assertOk()
        ->assertSee($paid->invoice_number)
        ->assertSee($unpaid->invoice_number);

    $service->commit($committed, $this->actor);
    expect(ApInvoice::query()->where('source_document_type', 'petty_cash_expense_import')->count())->toBe(2)
        ->and(ApPayment::query()->count())->toBe(1);
});

it('settles paid imports from the selected bank on their historical business dates without touching wallets', function () {
    $bankLedger = LedgerAccount::query()->create([
        'company_id' => $this->company->id,
        'code' => '1100-BANK-IMPORT',
        'name' => 'Main Bank Import Account',
        'type' => 'asset',
        'account_class' => 'asset',
        'allow_direct_posting' => true,
        'is_active' => true,
    ]);
    $bank = BankAccount::query()->create([
        'company_id' => $this->company->id,
        'ledger_account_id' => $bankLedger->id,
        'name' => 'Main Bank Account',
        'code' => 'MAIN-BANK-IMPORT',
        'account_type' => 'checking',
        'currency_code' => 'KWD',
        'is_default' => true,
        'is_active' => true,
        'opening_balance' => 5000,
        'opening_balance_date' => '2026-01-01',
    ]);
    $startingWalletBalance = (float) $this->wallet->balance;
    $workbook = pettyCashBulkImportWorkbook([
        ['2026-07-31', 'BANK-001', '', 'BANK-JULY-1', '', 'Kitchen Supplies', '999999 | Ignored Wallet', 'TRUE', 'July bank expense', '1', '21', ''],
        ['2026-08-01', 'BANK-002', '', 'BANK-AUG-1', '', 'Kitchen Supplies', '', 'TRUE', 'August bank expense', '1', '10', ''],
        ['2026-08-02', 'BANK-003', '', 'BANK-AUG-2', '', 'Kitchen Supplies', '', 'FALSE', 'Unpaid August expense', '1', '5', ''],
    ]);

    $service = app(PettyCashImportService::class);
    $batch = $service->stageBulk(
        $workbook,
        $this->supplier->id,
        $this->category->id,
        null,
        true,
        $this->company->id,
        $this->actor,
        'bank_account',
        $bank->id,
    );

    expect($batch->status->value)->toBe('ready')
        ->and($batch->funding_source)->toBe('bank_account')
        ->and($batch->default_bank_account_id)->toBe($bank->id)
        ->and($batch->default_wallet_id)->toBeNull()
        ->and($batch->stats['unpaid_for_insufficient_balance'])->toBe(0)
        ->and($batch->invoices->every(fn ($invoice): bool => ($invoice->header['wallet_id'] ?? null) === null))->toBeTrue();

    $julyRow = $batch->rows->first(fn ($row): bool => $row->payload['description'] === 'July bank expense');
    $batch = app(PettyCashImportEditor::class)->updateRow(
        $julyRow,
        $batch->revision,
        ['unit_price' => '22'],
        $this->actor,
    );
    expect($batch->status->value)->toBe('ready')
        ->and($batch->funding_source)->toBe('bank_account')
        ->and($batch->stats['unpaid_for_insufficient_balance'])->toBe(0);

    $service->commit($batch, $this->actor);

    $payments = ApPayment::query()->orderBy('payment_date')->get();
    $transactions = BankTransaction::query()->where('transaction_type', 'ap_payment')->orderBy('transaction_date')->get();
    $unpaid = ApInvoice::query()->where('reference_number', 'BANK-AUG-2')->firstOrFail();
    expect($payments)->toHaveCount(2)
        ->and($payments->pluck('payment_method')->unique()->all())->toBe(['bank_transfer'])
        ->and($payments->pluck('bank_account_id')->unique()->all())->toBe([$bank->id])
        ->and($payments->map(fn ($payment) => $payment->payment_date->format('Y-m-d'))->all())->toBe(['2026-07-31', '2026-08-01'])
        ->and($transactions)->toHaveCount(2)
        ->and($transactions->pluck('direction')->unique()->all())->toBe(['outflow'])
        ->and($transactions->map(fn ($transaction) => $transaction->transaction_date->format('Y-m-d'))->all())->toBe(['2026-07-31', '2026-08-01'])
        ->and($unpaid->status)->toBe('posted')
        ->and($unpaid->expenseProfile->channel)->toBe('vendor')
        ->and($unpaid->expenseProfile->wallet_id)->toBeNull()
        ->and((float) $this->wallet->fresh()->balance)->toBe($startingWalletBalance);

    $paymentEntryIds = SubledgerEntry::query()
        ->where('source_type', 'ap_payment')
        ->where('event', 'payment')
        ->pluck('id');
    expect((float) SubledgerLine::query()
        ->whereIn('entry_id', $paymentEntryIds)
        ->where('account_id', $bankLedger->id)
        ->sum('credit'))->toBe(32.0);

    $cashActivity = app(DashboardCashActivityService::class);
    expect($cashActivity->forRange([$this->company->id], '2026-07-01', '2026-07-31')['outflow_total'])->toBe(22.0)
        ->and($cashActivity->forRange([$this->company->id], '2026-08-01', '2026-08-31')['outflow_total'])->toBe(10.0);
});

it('rejects bank-funded staging when the company has no active linked bank account', function () {
    BankAccount::query()->where('company_id', $this->company->id)->update(['is_active' => false]);
    $workbook = pettyCashImportWorkbook([
        ['BANK-MISSING-1', $this->supplier->id.' | Daily Market', 'BANK-MISSING-REF', '', '', '', 'TRUE', 'Bank expense', '1', '10', ''],
    ]);

    expect(fn () => app(PettyCashImportService::class)->stage(
        $workbook,
        '2026-08-15',
        $this->category->id,
        null,
        $this->company->id,
        $this->actor,
        'bank_account',
        null,
    ))->toThrow(ValidationException::class, 'Choose an active bank account for this company.');
});

it('commits a ready batch through the review page action and redirects to created invoice links', function () {
    $workbook = pettyCashImportWorkbook([
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'UI-COMMIT-001', '', '', '', 'FALSE', 'Direct UI commit', '1', '15.00', ''],
    ]);
    $batch = app(PettyCashImportService::class)->stage(
        $workbook,
        '2026-08-15',
        $this->category->id,
        $this->wallet->id,
        $this->company->id,
        $this->actor
    );

    $this->actingAs($this->actor);
    Volt::test('petty-cash.imports.show', ['batch' => $batch->id])
        ->call('commitImport')
        ->assertHasNoErrors()
        ->assertRedirect(route('petty-cash.imports.show', ['batch' => $batch->id]));

    $invoice = ApInvoice::query()->where('reference_number', 'UI-COMMIT-001')->firstOrFail();

    expect($batch->fresh()->status->value)->toBe('completed')
        ->and($invoice->status)->toBe('posted')
        ->and($invoice->items)->toHaveCount(1)
        ->and($batch->invoices()->firstOrFail()->target_invoice_id)->toBe($invoice->id);

    $this->get(route('petty-cash.imports.show', ['batch' => $batch->id]))
        ->assertOk()
        ->assertSee('This import has been committed.')
        ->assertSee('Open AP Invoice')
        ->assertSee(route('payables.invoices.show', $invoice), false);
});

it('inherits invoice fields across compact line rows and ignores unused entry slots', function () {
    $lineCategory = ExpenseCategory::factory()->create(['name' => 'Fresh Food', 'active' => true]);
    $workbook = pettyCashImportWorkbook([
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'COMPACT-001', '', $lineCategory->id.' | Fresh Food', $this->wallet->id.' | Main Wallet', 'TRUE', 'Rice', '1', '10.00', 'Morning run'],
        ['', '', '', '', '', '', '', 'Oil', '2', '5.00', ''],
        ['ENTRY-002'],
    ]);

    $batch = app(PettyCashImportService::class)->stage(
        $workbook,
        '2026-08-15',
        $this->category->id,
        $this->wallet->id,
        $this->company->id,
        $this->actor
    );

    expect($batch->status->value)->toBe('ready')
        ->and($batch->invoices)->toHaveCount(1)
        ->and($batch->rows)->toHaveCount(2)
        ->and($batch->invoices->first()->entry_id)->toBe('ENTRY-001')
        ->and($batch->invoices->first()->header['category_id'])->toBe($lineCategory->id)
        ->and((float) $batch->invoices->first()->header['tax_amount'])->toBe(0.0)
        ->and($batch->rows->last()->payload['supplier_id'])->toBe($this->supplier->id)
        ->and($batch->rows->last()->payload['category_id'])->toBe($lineCategory->id)
        ->and($batch->rows->last()->payload['paid'])->toBeTrue()
        ->and($batch->rows->last()->payload['notes'])->toBe('Morning run');
});

it('allows different notes on rows grouped into the same invoice', function () {
    $workbook = pettyCashImportWorkbook([
        ['ENTRY-NOTES-1', $this->supplier->id.' | Daily Market', 'NOTES-REF-1', '', '', '', 'FALSE', 'First line', '1', '10.00', 'First line note'],
        ['ENTRY-NOTES-1', $this->supplier->id.' | Daily Market', 'NOTES-REF-1', '', '', '', 'FALSE', 'Second line', '1', '5.00', 'Second line note'],
    ]);

    $batch = app(PettyCashImportService::class)->stage(
        $workbook,
        '2026-08-15',
        $this->category->id,
        $this->wallet->id,
        $this->company->id,
        $this->actor
    );

    expect($batch->status->value)->toBe('ready')
        ->and($batch->invoices)->toHaveCount(1)
        ->and($batch->invoices->first()->errors)->toBeEmpty()
        ->and($batch->invoices->first()->header['notes'])->toBe('First line note')
        ->and($batch->rows->pluck('payload')->pluck('notes')->all())->toBe([
            'First line note',
            'Second line note',
        ]);
});

it('rejects a workbook containing only prefilled entry slots', function () {
    $workbook = pettyCashImportWorkbook([
        ['ENTRY-001'],
        ['ENTRY-001'],
        ['ENTRY-002'],
    ]);

    expect(fn () => app(PettyCashImportService::class)->stage(
        $workbook,
        '2026-08-15',
        $this->category->id,
        $this->wallet->id,
        $this->company->id,
        $this->actor
    ))->toThrow(ValidationException::class, 'The workbook must contain at least one expense line.');
});

it('persists duplicate and conflicting grouped entries as a failed validation batch', function () {
    $workbook = pettyCashImportWorkbook([
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'SAME-REF', '', '', '', 'TRUE', 'Rice', '1', '10', ''],
        ['ENTRY-002', $this->supplier->id.' | Daily Market', 'SAME-REF', '', '', '', 'FALSE', 'Oil', '1', '5', ''],
        ['ENTRY-003', $this->supplier->id.' | Daily Market', 'THIRD', '', '', '', 'TRUE', 'Line one', '1', '4', ''],
        ['ENTRY-003', $this->supplier->id.' | Daily Market', 'THIRD', '', '', '', 'FALSE', 'Line two', '1', '3', ''],
    ]);

    $batch = app(PettyCashImportService::class)->stage(
        $workbook,
        '2026-08-15',
        $this->category->id,
        $this->wallet->id,
        $this->company->id,
        $this->actor
    );

    expect($batch->status->value)->toBe('failed')
        ->and($batch->stats['invalid_invoices'])->toBe(3)
        ->and($batch->invoices->firstWhere('entry_id', 'ENTRY-001')->errors)->not->toBeEmpty()
        ->and($batch->invoices->firstWhere('entry_id', 'ENTRY-002')->errors)->not->toBeEmpty()
        ->and($batch->invoices->firstWhere('entry_id', 'ENTRY-003')->errors)->not->toBeEmpty()
        ->and(ApInvoice::query()->where('source_document_type', 'petty_cash_expense_import')->exists())->toBeFalse();
});

it('rejects an existing supplier reference on the same business date at stage', function () {
    ApInvoice::query()->create([
        'company_id' => $this->company->id,
        'supplier_id' => $this->supplier->id,
        'invoice_number' => 'EXISTING-001',
        'reference_number' => 'ALREADY-USED',
        'invoice_date' => '2026-08-15',
        'due_date' => '2026-08-15',
        'subtotal' => 1,
        'tax_amount' => 0,
        'total_amount' => 1,
        'status' => 'posted',
        'created_by' => $this->actor->id,
    ]);
    $workbook = pettyCashImportWorkbook([
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'ALREADY-USED', '', '', '', 'FALSE', 'Rice', '1', '10', ''],
    ]);

    $batch = app(PettyCashImportService::class)->stage(
        $workbook,
        '2026-08-15',
        $this->category->id,
        $this->wallet->id,
        $this->company->id,
        $this->actor
    );

    expect($batch->status->value)->toBe('failed')
        ->and($batch->stats['invalid_invoices'])->toBe(1);
});

it('imports paid entries as unpaid when wallet capacity is insufficient at stage or commit', function () {
    $this->wallet->forceFill(['balance' => 25])->save();
    $workbook = pettyCashImportWorkbook([
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'ROLL-1', '', '', '', 'TRUE', 'Rice', '1', '20', ''],
        ['ENTRY-002', $this->supplier->id.' | Daily Market', 'ROLL-2', '', '', '', 'TRUE', 'Oil', '1', '10', ''],
    ]);
    $service = app(PettyCashImportService::class);
    $batch = $service->stage(
        $workbook,
        '2026-08-15',
        $this->category->id,
        $this->wallet->id,
        $this->company->id,
        $this->actor
    );
    expect($batch->status->value)->toBe('ready')
        ->and($batch->stats['unpaid_for_insufficient_balance'])->toBe(1)
        ->and($batch->invoices->firstWhere('entry_id', 'ENTRY-001')->header['paid'])->toBeTrue()
        ->and($batch->invoices->firstWhere('entry_id', 'ENTRY-002')->header['paid'])->toBeFalse()
        ->and($batch->invoices->firstWhere('entry_id', 'ENTRY-002')->header['paid_requested'])->toBeTrue()
        ->and($batch->invoices->firstWhere('entry_id', 'ENTRY-002')->errors)->toBeEmpty();

    $this->wallet->forceFill(['balance' => 15])->save();
    $committed = $service->commit($batch, $this->actor);

    $first = ApInvoice::query()->where('reference_number', 'ROLL-1')->firstOrFail();
    $second = ApInvoice::query()->where('reference_number', 'ROLL-2')->firstOrFail();

    expect($committed->status->value)->toBe('completed')
        ->and($committed->stats['unpaid_for_insufficient_balance'])->toBe(2)
        ->and($first->status)->toBe('posted')
        ->and($second->status)->toBe('posted')
        ->and(ApPayment::query()->exists())->toBeFalse()
        ->and((float) $this->wallet->fresh()->balance)->toBe(15.0)
        ->and($committed->invoices->every(fn ($invoice): bool => $invoice->header['paid'] === false))->toBeTrue()
        ->and($committed->invoices->every(fn ($invoice): bool => filled($invoice->header['settlement_warning'] ?? null)))->toBeTrue();
});

it('rolls back work already performed when a later staged supplier becomes blocked', function () {
    $secondSupplier = Supplier::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'Second Market',
        'status' => 'active',
        'default_expense_account_id' => $this->expenseAccount->id,
        'hold_status' => 'open',
    ]);
    $workbook = pettyCashImportWorkbook([
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'LATER-1', '', '', '', 'TRUE', 'Rice', '1', '20', ''],
        ['ENTRY-002', $secondSupplier->id.' | Second Market', 'LATER-2', '', '', '', 'TRUE', 'Oil', '1', '10', ''],
    ]);
    $service = app(PettyCashImportService::class);
    $batch = $service->stage(
        $workbook,
        '2026-08-15',
        $this->category->id,
        $this->wallet->id,
        $this->company->id,
        $this->actor
    );
    expect($batch->status->value)->toBe('ready');

    $secondSupplier->forceFill(['hold_status' => 'hold'])->save();
    expect(fn () => $service->commit($batch, $this->actor))
        ->toThrow(ValidationException::class);

    expect(ApInvoice::query()->where('source_document_type', 'petty_cash_expense_import')->exists())->toBeFalse()
        ->and(ApPayment::query()->exists())->toBeFalse()
        ->and(SubledgerEntry::query()->whereIn('source_type', ['ap_invoice', 'ap_payment'])->exists())->toBeFalse()
        ->and((float) $this->wallet->fresh()->balance)->toBe(1000.0)
        ->and(PettyCashImportBatch::query()->findOrFail($batch->id)->status->value)->toBe('ready');
});

it('returns a workbook validation error for formula-bearing spreadsheets', function () {
    $workbook = pettyCashImportWorkbook([
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'FORMULA-1', '', '', '', 'FALSE', 'Rice', '1', '10', ''],
    ]);
    $zip = new ZipArchive;
    expect($zip->open($workbook->getRealPath()))->toBeTrue();
    try {
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $sheet = str_replace(
            '<c r="J2" t="inlineStr"><is><t>10</t></is></c>',
            '<c r="J2"><f>5+5</f><v>10</v></c>',
            $sheet
        );
        $zip->addFromString('xl/worksheets/sheet2.xml', $sheet);
    } finally {
        $zip->close();
    }

    try {
        app(PettyCashImportService::class)->stage(
            $workbook,
            '2026-08-15',
            $this->category->id,
            $this->wallet->id,
            $this->company->id,
            $this->actor
        );
        $this->fail('Expected formula-bearing workbook validation to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('workbook')
            ->and($exception->errors()['workbook'][0])->toContain('Formulas are not allowed');
    }
});

it('normalizes entry ids and accepts four-decimal unit prices', function () {
    $workbook = pettyCashImportWorkbook([
        ['daily-001', $this->supplier->id.' | Daily Market', 'PRECISION-1', '', '', '', 'FALSE', 'Measured item', '3', '0.3333', ''],
        ['DAILY-001', $this->supplier->id.' | Daily Market', 'PRECISION-1', '', '', '', 'FALSE', 'Second item', '1', '1.0001', ''],
    ]);

    $batch = app(PettyCashImportService::class)->stage(
        $workbook,
        '2026-08-15',
        $this->category->id,
        $this->wallet->id,
        $this->company->id,
        $this->actor
    );

    expect($batch->status->value)->toBe('ready')
        ->and($batch->invoices)->toHaveCount(1)
        ->and($batch->invoices->first()->entry_id)->toBe('DAILY-001')
        ->and((float) $batch->rows->first()->payload['unit_price'])->toBe(0.3333)
        ->and((float) $batch->stats['total_amount'])->toBe(2.0);
});

it('revalidates supplier references after another ready batch commits', function () {
    $alternateCategory = ExpenseCategory::factory()->create(['name' => 'Alternate Supplies', 'active' => true]);
    $workbook = pettyCashImportWorkbook([
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'SERIALIZED-1', '', '', '', 'FALSE', 'Rice', '1', '10', ''],
    ]);
    $service = app(PettyCashImportService::class);
    $first = $service->stage(
        $workbook,
        '2026-08-15',
        $this->category->id,
        $this->wallet->id,
        $this->company->id,
        $this->actor
    );
    $second = $service->stage(
        $workbook,
        '2026-08-15',
        $alternateCategory->id,
        $this->wallet->id,
        $this->company->id,
        $this->actor
    );
    expect($first->id)->not->toBe($second->id)
        ->and($first->status->value)->toBe('ready')
        ->and($second->status->value)->toBe('ready');

    $service->commit($first, $this->actor);
    expect(fn () => $service->commit($second, $this->actor))
        ->toThrow(ValidationException::class);

    expect(ApInvoice::query()->where('reference_number', 'SERIALIZED-1')->count())->toBe(1)
        ->and($second->fresh()->status->value)->toBe('ready');
});

it('stages edits and atomically commits multiple dates with proposed categories', function () {
    $this->wallet->forceFill(['balance' => 25])->save();
    $workbook = pettyCashBulkImportWorkbook([
        ['2026-07-31', 'ENTRY-001', '', 'BULK-1', '', 'Bulk Food', '', '', 'Meat', '1', '15', ''],
        ['2026-07-31', 'ENTRY-001', '', 'BULK-1', '', 'Bulk Food', '', '', 'Fish', '1', '5', ''],
        ['2026-08-01', 'ENTRY-001', '', 'BULK-2', '', 'Bulk Operations', '', '', 'Transfer fee', '1', '10', ''],
    ], [
        ['01', 'Bulk Food'],
        ['19', 'Bulk Unused Category'],
    ]);

    $service = app(PettyCashImportService::class);
    $batch = $service->stageBulk(
        $workbook,
        $this->supplier->id,
        null,
        $this->wallet->id,
        true,
        $this->company->id,
        $this->actor,
    );

    expect($batch->import_mode)->toBe('bulk')
        ->and($batch->status->value)->toBe('ready')
        ->and($batch->date_from->format('Y-m-d'))->toBe('2026-07-31')
        ->and($batch->date_to->format('Y-m-d'))->toBe('2026-08-01')
        ->and($batch->invoices)->toHaveCount(2)
        ->and($batch->categoryProposals)->toHaveCount(3)
        ->and($batch->invoices->pluck('entry_id')->all())->toBe(['ENTRY-001', 'ENTRY-001']);

    $fish = $batch->rows->first(fn ($row): bool => $row->payload['description'] === 'Fish');
    $edited = app(PettyCashImportEditor::class)->updateRow(
        $fish,
        $batch->revision,
        ['unit_price' => '6'],
        $this->actor,
    );
    expect($edited->revision)->toBe(1)
        ->and((float) $fish->fresh()->payload['unit_price'])->toBe(6.0)
        ->and((float) $fish->fresh()->original_payload['unit_price'])->toBe(5.0)
        ->and(PettyCashImportEditEvent::query()->where('import_batch_id', $batch->id)->count())->toBe(1);
    $encryptedBefore = DB::table('petty_cash_import_edit_events')->value('before_values');
    expect($encryptedBefore)->not->toContain('Fish');

    expect(fn () => app(PettyCashImportEditor::class)->updateRow(
        $fish,
        0,
        ['unit_price' => '7'],
        $this->actor,
    ))->toThrow(ValidationException::class, 'changed in another session');

    $committed = $service->commit($edited, $this->actor);
    $july = ApInvoice::query()->where('reference_number', 'BULK-1')->firstOrFail();
    $august = ApInvoice::query()->where('reference_number', 'BULK-2')->firstOrFail();
    expect($committed->status->value)->toBe('completed')
        ->and($july->invoice_date->format('Y-m-d'))->toBe('2026-07-31')
        ->and($july->status)->toBe('paid')
        ->and($august->invoice_date->format('Y-m-d'))->toBe('2026-08-01')
        ->and($august->status)->toBe('posted')
        ->and((float) $this->wallet->fresh()->balance)->toBe(4.0)
        ->and(ExpenseCategory::query()->whereIn('name', [
            'Bulk Food', 'Bulk Operations', 'Bulk Unused Category',
        ])->count())->toBe(3);
});

it('keeps an existing category matched through revalidation and invoice edits with a long ID token', function () {
    ExpenseCategory::factory()->create(['name' => 'Other Active Category', 'active' => true]);
    $this->category->update(['name' => str_repeat('Long', 25)]);
    $batch = app(PettyCashImportService::class)->stageBulk(
        pettyCashBulkImportWorkbook([
            ['2026-07-31', 'MATCH-1', '', 'MATCH-REF-1', '', $this->category->name, '', 'FALSE', 'Line', '1', '5', ''],
        ]),
        $this->supplier->id,
        null,
        $this->wallet->id,
        false,
        $this->company->id,
        $this->actor,
    );

    $revalidated = app(PettyCashImportEditor::class)->revalidate($batch, $batch->revision, $this->actor);

    expect($revalidated->status->value)->toBe('ready')
        ->and($revalidated->stats['invalid_categories'])->toBe(0)
        ->and($revalidated->categoryProposals->first()->status)->toBe('matched')
        ->and($revalidated->categoryProposals->first()->expense_category_id)->toBe($this->category->id);

    $invoice = $revalidated->invoices->first();
    $this->actingAs($this->actor);
    Volt::test('petty-cash.imports.show', ['batch' => $batch->id])
        ->assertSet("invoiceForms.{$invoice->id}.category", $this->category->id.' | '.$this->category->name)
        ->set("invoiceForms.{$invoice->id}.notes", 'Only the notes changed')
        ->call('saveInvoice', $invoice->id)
        ->assertHasNoErrors();
    $committed = app(PettyCashImportService::class)->commit($batch->fresh(), $this->actor);
    expect($committed->status->value)->toBe('completed')
        ->and(ApInvoice::query()->where('reference_number', 'MATCH-REF-1')->firstOrFail()->category_id)->toBe($this->category->id);
});

it('maps an inactive category across multiple dates and commits without creating categories', function () {
    $inactive = ExpenseCategory::factory()->create(['name' => 'Retired Supplies', 'active' => false]);
    $service = app(PettyCashImportService::class);
    $batch = $service->stageBulk(
        pettyCashBulkImportWorkbook([
            ['2026-07-31', 'MAP-1', '', 'MAP-JULY', '', $inactive->name, '', 'FALSE', 'July supplies', '1', '5', ''],
            ['2026-08-01', 'MAP-2', '', 'MAP-AUGUST', '', $inactive->name, '', 'FALSE', 'August supplies', '2', '5', ''],
        ], [['OLD', $inactive->name]]),
        $this->supplier->id,
        null,
        $this->wallet->id,
        false,
        $this->company->id,
        $this->actor,
    );
    $proposal = $batch->categoryProposals->firstWhere('source_code', 'OLD');
    $categoryCount = ExpenseCategory::query()->count();
    expect($batch->status->value)->toBe('needs_review');

    $resolved = app(PettyCashImportEditor::class)->resolveCategory(
        $proposal, $batch->revision, ['category_id' => $this->category->id], $this->actor,
    );

    expect($resolved->status->value)->toBe('ready')
        ->and($resolved->stats['invalid_categories'])->toBe(0)
        ->and($resolved->stats['invalid_invoices'])->toBe(0)
        ->and($resolved->invoices->pluck('header.category_id')->unique()->all())->toBe([$this->category->id])
        ->and($resolved->rows->pluck('payload.category_id')->unique()->all())->toBe([$this->category->id])
        ->and($resolved->rows->pluck('original_payload.category')->unique()->all())->toBe([$inactive->name])
        ->and($proposal->fresh()->source_name)->toBe($inactive->name)
        ->and($proposal->fresh()->status)->toBe('mapped');

    $committed = $service->commit($resolved, $this->actor);
    $invoices = ApInvoice::query()->whereIn('reference_number', ['MAP-JULY', 'MAP-AUGUST'])->get();
    expect($committed->status->value)->toBe('completed')
        ->and($invoices)->toHaveCount(2)
        ->and($invoices->pluck('category_id')->unique()->all())->toBe([$this->category->id])
        ->and(ExpenseCategory::query()->count())->toBe($categoryCount)
        ->and($inactive->fresh()->active)->toBeFalse();
});

it('resolves an unused ambiguous declaration by ID and preserves it through edits and commit', function () {
    $chosen = ExpenseCategory::factory()->create(['name' => 'Ambiguous Declared', 'active' => true]);
    ExpenseCategory::factory()->create(['name' => 'ambiguous   declared', 'active' => true]);
    $workbook = pettyCashBulkImportWorkbook([
        ['2026-07-31', 'AMBIG-1', '', 'AMBIG-REF-1', '', '', '', 'FALSE', 'Line', '1', '5', ''],
    ], [['20', 'AMBIGUOUS DECLARED']]);

    $batch = app(PettyCashImportService::class)->stageBulk(
        $workbook,
        $this->supplier->id,
        $this->category->id,
        $this->wallet->id,
        false,
        $this->company->id,
        $this->actor,
    );

    expect($batch->status->value)->toBe('needs_review')
        ->and($batch->stats['invalid_categories'])->toBe(1)
        ->and($batch->categoryProposals->firstWhere('source_code', '20')->status)->toBe('ambiguous');

    $proposal = $batch->categoryProposals->firstWhere('source_code', '20');
    $editor = app(PettyCashImportEditor::class);
    $resolved = $editor->resolveCategory($proposal, $batch->revision, ['category_id' => $chosen->id], $this->actor);
    $edited = $editor->updateRow($resolved->rows->first(), $resolved->revision, ['unit_price' => '6'], $this->actor);

    expect($edited->status->value)->toBe('ready')
        ->and($edited->stats['invalid_categories'])->toBe(0)
        ->and($proposal->fresh()->status)->toBe('mapped')
        ->and($proposal->fresh()->expense_category_id)->toBe($chosen->id);

    $committed = app(PettyCashImportService::class)->commit($edited, $this->actor);
    expect($committed->status->value)->toBe('completed')
        ->and($proposal->fresh()->expense_category_id)->toBe($chosen->id)
        ->and(ApInvoice::query()->where('reference_number', 'AMBIG-REF-1')->firstOrFail()->category_id)->toBe($this->category->id);
});

it('merges a renamed proposal into another staged category and creates it only once on commit', function () {
    $service = app(PettyCashImportService::class);
    $batch = $service->stageBulk(
        pettyCashBulkImportWorkbook([
            ['2026-07-31', 'RENAME-1', '', 'RENAME-JULY', '', 'Original Supplies', '', 'FALSE', 'July supplies', '1', '5', ''],
            ['2026-08-01', 'RENAME-2', '', 'RENAME-AUGUST', '', 'Unified Supplies', '', 'FALSE', 'August supplies', '1', '5', ''],
        ], [['OLD', 'Original Supplies']]),
        $this->supplier->id,
        null,
        $this->wallet->id,
        false,
        $this->company->id,
        $this->actor,
    );
    $source = $batch->categoryProposals->firstWhere('source_name', 'Original Supplies');
    $target = $batch->categoryProposals->firstWhere('source_name', 'Unified Supplies');
    $categoryCount = ExpenseCategory::query()->count();

    $resolved = app(PettyCashImportEditor::class)->resolveCategory(
        $source, $batch->revision, ['name' => 'Unified Supplies'], $this->actor,
    );
    expect($resolved->status->value)->toBe('ready')
        ->and($resolved->categoryProposals)->toHaveCount(1)
        ->and($resolved->categoryProposals->first()->id)->toBe($target->id)
        ->and($resolved->categoryProposals->first()->is_declared)->toBeTrue()
        ->and($resolved->rows->pluck('payload.category')->unique()->all())->toBe(['Unified Supplies'])
        ->and($resolved->rows->pluck('original_payload.category')->all())->toBe(['Original Supplies', 'Unified Supplies'])
        ->and(ExpenseCategory::query()->count())->toBe($categoryCount);

    $committed = $service->commit($resolved, $this->actor);
    $created = ExpenseCategory::query()->where('name', 'Unified Supplies')->firstOrFail();
    expect($committed->status->value)->toBe('completed')
        ->and(ExpenseCategory::query()->count())->toBe($categoryCount + 1)
        ->and(ApInvoice::query()->whereIn('reference_number', ['RENAME-JULY', 'RENAME-AUGUST'])
            ->pluck('category_id')->unique()->all())->toBe([$created->id]);
});

it('completes category review in Livewire and preserves an explicit duplicate-name choice when editing an invoice', function () {
    $chosen = ExpenseCategory::factory()->create(['name' => 'Duplicate Supplies', 'active' => true]);
    ExpenseCategory::factory()->create(['name' => 'duplicate   supplies', 'active' => true]);
    $batch = app(PettyCashImportService::class)->stageBulk(
        pettyCashBulkImportWorkbook([
            ['2026-07-31', 'UI-MAPPED', '', 'UI-MAPPED-REF', '', 'DUPLICATE SUPPLIES', '', 'FALSE', 'Line', '1', '5', ''],
        ]),
        $this->supplier->id,
        null,
        $this->wallet->id,
        false,
        $this->company->id,
        $this->actor,
    );
    $proposal = $batch->categoryProposals->first();
    $invoice = $batch->invoices->first();
    $this->actingAs($this->actor);
    expect($batch->status->value)->toBe('needs_review');

    $component = Volt::test('petty-cash.imports.show', ['batch' => $batch->id])
        ->set("categoryForms.{$proposal->id}.mode", 'existing')
        ->set("categoryForms.{$proposal->id}.category_id", (string) $chosen->id)
        ->call('saveCategory', $proposal->id)
        ->assertHasNoErrors()
        ->assertSet("invoiceForms.{$invoice->id}.category", $chosen->id.' | '.$chosen->name)
        ->set("invoiceForms.{$invoice->id}.notes", 'Reviewed without changing the category')
        ->call('saveInvoice', $invoice->id)
        ->assertHasNoErrors()
        ->assertSee('Confirm and Commit');
    expect($invoice->fresh()->header['category_id'])->toBe($chosen->id)
        ->and($batch->rows->first()->fresh()->payload['_category_proposal_id'])->toBe($proposal->id);

    $component->call('commitImport')->assertHasNoErrors()
        ->assertRedirect(route('petty-cash.imports.show', ['batch' => $batch->id]));
    expect($batch->fresh()->status->value)->toBe('completed')
        ->and(ApInvoice::query()->where('reference_number', 'UI-MAPPED-REF')->firstOrFail()->category_id)->toBe($chosen->id);
});

it('remaps only the owned rows when separate source categories share an existing target', function () {
    $alternative = ExpenseCategory::factory()->create(['name' => 'Alternative Supplies', 'active' => true]);
    $batch = app(PettyCashImportService::class)->stageBulk(
        pettyCashBulkImportWorkbook([
            ['2026-07-31', 'OWNED-1', '', 'OWNED-FIRST', '', 'First Source', '', 'FALSE', 'First line', '1', '5', ''],
            ['2026-08-01', 'OWNED-2', '', 'OWNED-SECOND', '', 'Second Source', '', 'FALSE', 'Second line', '1', '5', ''],
        ]),
        $this->supplier->id,
        null,
        $this->wallet->id,
        false,
        $this->company->id,
        $this->actor,
    );
    $first = $batch->categoryProposals->firstWhere('source_name', 'First Source');
    $second = $batch->categoryProposals->firstWhere('source_name', 'Second Source');
    $editor = app(PettyCashImportEditor::class);
    foreach ([$first, $second] as $proposal) {
        $batch = $editor->resolveCategory($proposal, $batch->revision, ['category_id' => $this->category->id], $this->actor);
    }
    $batch = $editor->resolveCategory($first, $batch->revision, ['category_id' => $alternative->id], $this->actor);

    expect($batch->status->value)->toBe('ready')
        ->and($batch->rows->pluck('payload.category_id')->all())->toBe([$alternative->id, $this->category->id])
        ->and($batch->rows->pluck('payload._category_proposal_id')->all())->toBe([$first->id, $second->id])
        ->and($batch->rows->pluck('original_payload.category')->all())->toBe(['First Source', 'Second Source']);
    app(PettyCashImportService::class)->commit($batch, $this->actor);
    expect(ApInvoice::query()->where('reference_number', 'OWNED-FIRST')->firstOrFail()->category_id)->toBe($alternative->id)
        ->and(ApInvoice::query()->where('reference_number', 'OWNED-SECOND')->firstOrFail()->category_id)->toBe($this->category->id);
});

it('rejects merging a new category name into an already mapped source proposal without altering either source', function () {
    $batch = app(PettyCashImportService::class)->stageBulk(
        pettyCashBulkImportWorkbook([
            ['2026-07-31', 'ALIAS-1', '', 'ALIAS-MAPPED', '', 'Reviewed Alias', '', 'FALSE', 'Mapped line', '1', '5', ''],
            ['2026-08-01', 'ALIAS-2', '', 'ALIAS-UNREVIEWED', '', 'Unreviewed Source', '', 'FALSE', 'Unreviewed line', '1', '5', ''],
        ]),
        $this->supplier->id,
        null,
        $this->wallet->id,
        false,
        $this->company->id,
        $this->actor,
    );
    $mapped = $batch->categoryProposals->firstWhere('source_name', 'Reviewed Alias');
    $unreviewed = $batch->categoryProposals->firstWhere('source_name', 'Unreviewed Source');
    $editor = app(PettyCashImportEditor::class);
    $batch = $editor->resolveCategory($mapped, $batch->revision, ['category_id' => $this->category->id], $this->actor);
    $payloads = $batch->rows->pluck('payload', 'id')->all();

    expect(fn () => $editor->resolveCategory($unreviewed, $batch->revision, ['name' => 'Reviewed Alias'], $this->actor))
        ->toThrow(ValidationException::class);
    expect($batch->fresh()->revision)->toBe($batch->revision)
        ->and($batch->rows()->get()->pluck('payload', 'id')->all())->toBe($payloads)
        ->and($mapped->fresh()->status)->toBe('mapped')
        ->and($mapped->fresh()->expense_category_id)->toBe($this->category->id)
        ->and($unreviewed->fresh()->status)->toBe('proposed')
        ->and($unreviewed->fresh()->source_name)->toBe('Unreviewed Source')
        ->and($batch->categoryProposals()->count())->toBe(2);
});

it('rejects missing inactive and stale category mappings without changing staged expenses', function () {
    $inactive = ExpenseCategory::factory()->create(['name' => 'Inactive Mapping Target', 'active' => false]);
    $batch = app(PettyCashImportService::class)->stageBulk(
        pettyCashBulkImportWorkbook([
            ['2026-07-31', 'INVALID-MAP', '', 'INVALID-MAP-REF', '', 'Unmapped Supplies', '', 'FALSE', 'Line', '1', '5', ''],
        ]),
        $this->supplier->id,
        null,
        $this->wallet->id,
        false,
        $this->company->id,
        $this->actor,
    );
    $proposal = $batch->categoryProposals->first();
    $editor = app(PettyCashImportEditor::class);
    $originalPayload = $batch->rows->first()->payload;
    foreach ([(int) ExpenseCategory::query()->max('id') + 1, $inactive->id] as $categoryId) {
        expect(fn () => $editor->resolveCategory($proposal, $batch->revision, ['category_id' => $categoryId], $this->actor))
            ->toThrow(ValidationException::class);
    }
    expect($batch->fresh()->revision)->toBe($batch->revision)
        ->and($batch->rows->first()->fresh()->payload)->toBe($originalPayload)
        ->and($proposal->fresh()->status)->toBe('proposed');

    $resolved = $editor->resolveCategory($proposal, $batch->revision, ['category_id' => $this->category->id], $this->actor);
    expect(fn () => $editor->resolveCategory($proposal, $batch->revision, ['category_id' => $this->category->id], $this->actor))
        ->toThrow(ValidationException::class, 'changed in another session');
    expect($batch->fresh()->revision)->toBe($resolved->revision)
        ->and($proposal->fresh()->expense_category_id)->toBe($this->category->id);
});

it('rolls back a commit when a mapped category becomes inactive', function () {
    $service = app(PettyCashImportService::class);
    $batch = $service->stageBulk(
        pettyCashBulkImportWorkbook([
            ['2026-07-31', 'CHANGED-MAP', '', 'CHANGED-MAP-REF', '', 'Mapped Supplies', '', 'FALSE', 'Line', '1', '5', ''],
        ], [['NEW', 'Additional Proposed Category']]),
        $this->supplier->id,
        null,
        $this->wallet->id,
        false,
        $this->company->id,
        $this->actor,
    );
    $resolved = app(PettyCashImportEditor::class)->resolveCategory(
        $batch->categoryProposals->firstWhere('source_name', 'Mapped Supplies'),
        $batch->revision,
        ['category_id' => $this->category->id],
        $this->actor,
    );
    $this->category->forceFill(['active' => false])->save();

    expect(fn () => $service->commit($resolved, $this->actor))->toThrow(ValidationException::class);
    expect(ApInvoice::query()->where('reference_number', 'CHANGED-MAP-REF')->exists())->toBeFalse()
        ->and(ExpenseCategory::query()->where('name', 'Additional Proposed Category')->exists())->toBeFalse()
        ->and($batch->fresh()->status->value)->toBe('ready');
});

it('keeps an unavailable mapping blocked through revalidation and restores it when the category is active', function () {
    $target = ExpenseCategory::factory()->create(['name' => 'Temporarily Unavailable Category', 'active' => true]);
    $batch = app(PettyCashImportService::class)->stageBulk(
        pettyCashBulkImportWorkbook([
            ['2026-07-31', 'UNAVAILABLE-MAP', '', 'UNAVAILABLE-MAP-REF', '', '', '', 'FALSE', 'Line', '1', '5', ''],
        ], [['UNUSED', 'Unused Mapped Declaration']]),
        $this->supplier->id,
        $this->category->id,
        $this->wallet->id,
        false,
        $this->company->id,
        $this->actor,
    );
    $proposal = $batch->categoryProposals->firstWhere('source_code', 'UNUSED');
    $editor = app(PettyCashImportEditor::class);
    $batch = $editor->resolveCategory($proposal, $batch->revision, ['category_id' => $target->id], $this->actor);
    $target->update(['active' => false]);

    foreach ([1, 2] as $attempt) {
        $batch = $editor->revalidate($batch, $batch->revision, $this->actor);
        expect($batch->status->value)->toBe('needs_review')
            ->and($batch->stats['invalid_categories'])->toBe(1)
            ->and($proposal->fresh()->status)->toBe('mapped_inactive')
            ->and($proposal->fresh()->expense_category_id)->toBe($target->id);
    }

    $target->update(['active' => true]);
    $batch = $editor->revalidate($batch, $batch->revision, $this->actor);
    expect($batch->status->value)->toBe('ready')
        ->and($batch->stats['invalid_categories'])->toBe(0)
        ->and($proposal->fresh()->status)->toBe('mapped')
        ->and($proposal->fresh()->expense_category_id)->toBe($target->id);
});

it('rolls back categories and all dates when a bulk commit fails', function () {
    $workbook = pettyCashBulkImportWorkbook([
        ['2026-07-31', 'ROLLBACK-1', '', 'BULK-RB-1', '', 'Bulk Rollback Category', '', 'FALSE', 'Line', '1', '5', ''],
    ], [['RB', 'Bulk Declared Rollback']]);
    $batch = app(PettyCashImportService::class)->stageBulk(
        $workbook,
        $this->supplier->id,
        null,
        $this->wallet->id,
        false,
        $this->company->id,
        $this->actor,
    );
    $this->supplier->forceFill(['hold_status' => 'hold'])->save();

    expect(fn () => app(PettyCashImportService::class)->commit($batch, $this->actor))
        ->toThrow(ValidationException::class);
    expect(ApInvoice::query()->where('reference_number', 'BULK-RB-1')->exists())->toBeFalse()
        ->and(ExpenseCategory::query()->whereIn('name', [
            'Bulk Rollback Category', 'Bulk Declared Rollback',
        ])->exists())->toBeFalse();
});

it('stages a closed bulk date for review and becomes ready after revalidation', function () {
    $period = AccountingPeriod::query()
        ->where('company_id', $this->company->id)
        ->whereDate('start_date', '<=', '2026-07-31')
        ->whereDate('end_date', '>=', '2026-07-31')
        ->firstOrFail();
    $period->forceFill(['status' => 'closed'])->save();
    $workbook = pettyCashBulkImportWorkbook([
        ['2026-07-31', 'CLOSED-1', '', 'CLOSED-BULK-1', '', 'Closed Review Category', '', 'FALSE', 'Line', '1', '5', ''],
    ]);

    $batch = app(PettyCashImportService::class)->stageBulk(
        $workbook,
        $this->supplier->id,
        null,
        $this->wallet->id,
        false,
        $this->company->id,
        $this->actor,
    );
    expect($batch->status->value)->toBe('needs_review')
        ->and($batch->invoices->first()->errors)->toHaveKey('business_date');

    $period->forceFill(['status' => 'open'])->save();
    $revalidated = app(PettyCashImportEditor::class)->revalidate($batch, 0, $this->actor);
    expect($revalidated->status->value)->toBe('ready')
        ->and($revalidated->invoices->first()->errors)->toBeEmpty();
});

function pettyCashImportMapping(AccountingCompany $company, string $key, string $code, string $type): LedgerAccount
{
    $account = LedgerAccount::query()->firstOrCreate(
        ['company_id' => $company->id, 'code' => $code],
        [
            'name' => str($key)->headline(),
            'type' => $type,
            'account_class' => $type,
            'allow_direct_posting' => true,
            'is_active' => true,
        ]
    );
    AccountingAccountMapping::query()->updateOrCreate(
        ['company_id' => $company->id, 'mapping_key' => $key],
        ['ledger_account_id' => $account->id]
    );

    return $account;
}

/** @param array<int, array<int, string>> $rows */
function pettyCashImportWorkbook(array $rows): UploadedFile
{
    $path = app(PettyCashImportTemplateBuilder::class)->build([], [], []);
    $zip = new ZipArchive;
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Unable to open generated petty cash import workbook.');
    }

    try {
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $xmlRows = '';
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $cells = '';
            foreach ($row as $column => $value) {
                if ($value === '') {
                    continue;
                }
                $reference = chr(ord('A') + $column).$rowNumber;
                $escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $cells .= '<c r="'.$reference.'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>';
            }
            $xmlRows .= '<row r="'.$rowNumber.'">'.$cells.'</row>';
        }
        preg_match('/<row r="1".*?<\/row>/s', $sheet, $headerMatch);
        $headerRow = $headerMatch[0] ?? throw new RuntimeException('The generated workbook header row is missing.');
        $sheet = preg_replace(
            '/<sheetData>.*?<\/sheetData>/s',
            '<sheetData>'.$headerRow.$xmlRows.'</sheetData>',
            $sheet,
            1
        ) ?? throw new RuntimeException('Unable to replace generated petty cash import rows.');
        $zip->addFromString('xl/worksheets/sheet2.xml', $sheet);
    } finally {
        $zip->close();
    }

    return new UploadedFile(
        $path,
        'petty-cash-daily.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true
    );
}

/**
 * @param  array<int, array<int, string>>  $rows
 * @param  array<int, array{0:string,1:string}>  $definitions
 */
function pettyCashBulkImportWorkbook(array $rows, array $definitions = []): UploadedFile
{
    $path = app(PettyCashImportTemplateBuilder::class)->buildBulk([], [], []);
    $zip = new ZipArchive;
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Unable to open generated bulk petty cash workbook.');
    }

    try {
        replacePettyCashWorkbookRows($zip, 'xl/worksheets/sheet2.xml', $rows);
        replacePettyCashWorkbookRows($zip, 'xl/worksheets/sheet3.xml', $definitions);
    } finally {
        $zip->close();
    }

    return new UploadedFile(
        $path,
        'petty-cash-bulk.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true
    );
}

/** @param array<int, array<int, string>> $rows */
function replacePettyCashWorkbookRows(ZipArchive $zip, string $sheetPath, array $rows): void
{
    $sheet = (string) $zip->getFromName($sheetPath);
    $xmlRows = '';
    foreach ($rows as $index => $row) {
        $rowNumber = $index + 2;
        $cells = '';
        foreach ($row as $column => $value) {
            if ($value === '') {
                continue;
            }
            $reference = chr(ord('A') + $column).$rowNumber;
            $escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $cells .= '<c r="'.$reference.'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>';
        }
        $xmlRows .= '<row r="'.$rowNumber.'">'.$cells.'</row>';
    }
    preg_match('/<row r="1".*?<\/row>/s', $sheet, $headerMatch);
    $header = $headerMatch[0] ?? throw new RuntimeException('Workbook header is missing.');
    $updated = preg_replace('/<sheetData>.*?<\/sheetData>/s', '<sheetData>'.$header.$xmlRows.'</sheetData>', $sheet, 1);
    $zip->addFromString($sheetPath, $updated ?: throw new RuntimeException('Unable to replace workbook rows.'));
}

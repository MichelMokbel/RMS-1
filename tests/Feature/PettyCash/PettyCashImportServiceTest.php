<?php

use App\Models\AccountingAccountMapping;
use App\Models\AccountingCompany;
use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Models\PettyCashImportBatch;
use App\Models\PettyCashWallet;
use App\Models\SubledgerEntry;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AP\ApReportsService;
use App\Services\PettyCash\PettyCashImportService;
use App\Services\PettyCash\PettyCashImportTemplateBuilder;
use App\Services\Spend\SpendReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
    pettyCashImportMapping($this->company, 'tax_input', '1305', 'asset');

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
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'RCPT-001', '', '', '', 'TRUE', 'Rice', '', '10.00', '2.00', 'Morning run'],
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'RCPT-001', '', '', '', 'TRUE', 'Oil', '2', '5.00', '2.00', 'Morning run'],
        ['ENTRY-002', $this->supplier->id.' | Daily Market', 'RCPT-002', '2026-08-18', $this->category->id.' | Kitchen Supplies', $this->wallet->id.' | Main Wallet', 'FALSE', 'Napkins', '3', '4.00', '0', ''],
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
        ->and((float) $batch->stats['tax_amount'])->toBe(2.0)
        ->and((float) $batch->stats['total_amount'])->toBe(34.0);

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
        ->and((float) $this->wallet->fresh()->balance)->toBe(978.0);

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
        ->and(round($report->sum('amount'), 2))->toBe(34.0);

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

it('persists duplicate and conflicting grouped entries as a failed validation batch', function () {
    $workbook = pettyCashImportWorkbook([
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'SAME-REF', '', '', '', 'TRUE', 'Rice', '1', '10', '0', ''],
        ['ENTRY-002', $this->supplier->id.' | Daily Market', 'SAME-REF', '', '', '', 'FALSE', 'Oil', '1', '5', '0', ''],
        ['ENTRY-003', $this->supplier->id.' | Daily Market', 'THIRD', '', '', '', 'TRUE', 'Line one', '1', '4', '0', ''],
        ['ENTRY-003', $this->supplier->id.' | Daily Market', 'THIRD', '', '', '', 'FALSE', 'Line two', '1', '3', '0', ''],
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
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'ALREADY-USED', '', '', '', 'FALSE', 'Rice', '1', '10', '0', ''],
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

it('rolls back every document when commit-time wallet state is no longer valid', function () {
    $workbook = pettyCashImportWorkbook([
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'ROLL-1', '', '', '', 'TRUE', 'Rice', '1', '20', '0', ''],
        ['ENTRY-002', $this->supplier->id.' | Daily Market', 'ROLL-2', '', '', '', 'TRUE', 'Oil', '1', '10', '0', ''],
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

    $this->wallet->forceFill(['balance' => 25])->save();

    expect(fn () => $service->commit($batch, $this->actor))
        ->toThrow(ValidationException::class);

    expect(ApInvoice::query()->where('source_document_type', 'petty_cash_expense_import')->exists())->toBeFalse()
        ->and(ApPayment::query()->exists())->toBeFalse()
        ->and((float) $this->wallet->fresh()->balance)->toBe(25.0)
        ->and(PettyCashImportBatch::query()->findOrFail($batch->id)->status->value)->toBe('ready')
        ->and(PettyCashImportBatch::query()->findOrFail($batch->id)->failure_reason)
        ->toBe('The import could not be committed. No documents were changed.');
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
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'LATER-1', '', '', '', 'TRUE', 'Rice', '1', '20', '0', ''],
        ['ENTRY-002', $secondSupplier->id.' | Second Market', 'LATER-2', '', '', '', 'TRUE', 'Oil', '1', '10', '0', ''],
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
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'FORMULA-1', '', '', '', 'FALSE', 'Rice', '1', '10', '0', ''],
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
        ['daily-001', $this->supplier->id.' | Daily Market', 'PRECISION-1', '', '', '', 'FALSE', 'Measured item', '3', '0.3333', '0', ''],
        ['DAILY-001', $this->supplier->id.' | Daily Market', 'PRECISION-1', '', '', '', 'FALSE', 'Second item', '1', '1.0001', '0', ''],
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
        ['ENTRY-001', $this->supplier->id.' | Daily Market', 'SERIALIZED-1', '', '', '', 'FALSE', 'Rice', '1', '10', '0', ''],
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
        $sheet = str_replace('</sheetData>', $xmlRows.'</sheetData>', $sheet);
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

<?php

use App\Models\AccountingAccountMapping;
use App\Models\AccountingAuditLog;
use App\Models\AccountingCompany;
use App\Models\ArClearingSettlementAdjustment;
use App\Models\BankAccount;
use App\Models\BankStatementImport;
use App\Models\BankTransaction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\GatewaySettlementImport;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentProviderTransaction;
use App\Models\PaymentSource;
use App\Models\SubledgerEntry;
use App\Models\User;
use App\Services\AR\ArClearingSettlementService;
use App\Services\Banking\BankReconciliationService;
use App\Services\Ledger\SubledgerService;
use App\Services\Payments\GatewaySettlementConflictException;
use App\Services\Payments\GatewaySettlementDuplicateImportException;
use App\Services\Payments\GatewaySettlementEvidenceService;
use App\Services\Payments\GatewaySettlementImportService;
use App\Services\Payments\GatewaySettlementPostingService;
use App\Services\Payments\GatewaySettlementReviewService;
use App\Services\Reports\UnsettledIncomingReceiptsReportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    config([
        'skipcash.settlements.enabled' => true,
        'skipcash.settlements.private_disk' => 'local',
        'skipcash.report_profiles.skipcash' => [
            'currency' => 'QAR',
            'timezone' => 'Asia/Qatar',
            'merchant' => 'Layla Kitchen',
            'branch_map' => [],
            'identifier_mapping' => [
                'report_field' => 'referenceNumber',
                'provider_field' => 'provider_payment_id',
                'evidence_reference' => 'synthetic-test-profile',
            ],
        ],
    ]);

    foreach (['gateway_settlements.import', 'gateway_settlements.review', 'gateway_settlements.post', 'gateway_settlements.void'] as $permission) {
        Permission::findOrCreate($permission);
    }
    Role::findOrCreate('admin');

    $this->actor = User::factory()->create(['status' => 'active']);
    $this->actor->assignRole('admin');
    $this->actor->givePermissionTo([
        'gateway_settlements.import',
        'gateway_settlements.review',
        'gateway_settlements.post',
        'gateway_settlements.void',
    ]);
    $this->company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $this->branch = Branch::query()->where('company_id', $this->company->id)->firstOrFail();
    config(['skipcash.report_profiles.skipcash.branch_map' => ['1' => $this->branch->id]]);

    $clearing = LedgerAccount::factory()->create([
        'company_id' => $this->company->id,
        'code' => 'SKIPCASH-IMPORT-TEST',
        'name' => 'SkipCash Import Test Clearing',
        'type' => 'asset',
    ]);
    $this->source = PaymentSource::query()->create([
        'company_id' => $this->company->id,
        'code' => 'skipcash',
        'name' => 'SkipCash',
        'method' => 'skipcash',
        'clearing_account_id' => $clearing->id,
        'is_active' => true,
        'created_by' => $this->actor->id,
    ]);
});

it('stages a private exact SkipCash payout without financial effects', function (): void {
    $import = app(GatewaySettlementImportService::class)->stage(
        skipCashSettlementWorkbook(),
        $this->source,
        $this->actor,
    );

    expect($import->gross_cents)->toBe(427000)
        ->and($import->commission_cents)->toBe(9921)
        ->and($import->settlement_fee_cents)->toBe(600)
        ->and($import->net_cents)->toBe(416479)
        ->and($import->totals_complete)->toBeTrue()
        ->and($import->review_state)->toBe('draft')
        ->and($import->rows)->toHaveCount(2)
        ->and($import->rows[0]->row_reference)->toBe('000123')
        ->and($import->rows[0]->physical_row)->toBe(2)
        ->and($import->rows[1]->physical_row)->toBe(3)
        ->and($import->rows[1]->order_type)->toBe('settlement_fee')
        ->and($import->rows[1]->settlement_fee_cents)->toBe(600)
        ->and($import->rows[1]->net_cents)->toBe(-600)
        ->and($import->rows[0]->customer_phone)->toBe('+97455551234')
        ->and($import->rows[0]->getRawOriginal('customer_phone'))->not->toContain('55551234');

    Storage::disk('local')->assertExists($import->getRawOriginal('object_key'));
    expect(Storage::disk('local')->getVisibility($import->getRawOriginal('object_key')))->toBe('private')
        ->and(DB::table('payments')->count())->toBe(0)
        ->and(DB::table('ar_clearing_settlements')->count())->toBe(0)
        ->and(DB::table('bank_transactions')->count())->toBe(0)
        ->and(DB::table('subledger_entries')->count())->toBe(0);
});

it('retains invalid report rows as blocked evidence', function (): void {
    $import = app(GatewaySettlementImportService::class)->stage(
        skipCashSettlementWorkbook(['grossAmount' => '4270.001']),
        $this->source,
        $this->actor,
    );

    $row = $import->rows->first();
    expect($import->totals_complete)->toBeFalse()
        ->and($import->review_state)->toBe('blocked')
        ->and($row->match_state)->toBe('blocked')
        ->and($row->gross_cents)->toBeNull()
        ->and($row->error_code)->toBe('AMOUNT_INVALID')
        ->and($row->source_evidence['errors'])->toContain(
            'Money values must be exact decimals with no more than two decimal places.',
        );
});

it('returns the existing authorized reference for a duplicate file', function (): void {
    $service = app(GatewaySettlementImportService::class);
    $workbook = skipCashSettlementWorkbook();
    $first = $service->stage($workbook, $this->source, $this->actor);

    try {
        $service->stage($workbook, $this->source, $this->actor);
        $this->fail('Expected a duplicate import exception.');
    } catch (GatewaySettlementDuplicateImportException $exception) {
        expect($exception->existingImport->id)->toBe($first->id)
            ->and(GatewaySettlementImport::query()->count())->toBe(1);
    }
});

it('retains reordered reexports while counting each economic row once', function (): void {
    $service = app(GatewaySettlementImportService::class);
    $first = $service->stage(skipCashSettlementWorkbook(), $this->source, $this->actor);
    $second = $service->stage(skipCashSettlementWorkbook(reverseRows: true), $this->source, $this->actor);
    $summary = app(GatewaySettlementReviewService::class)
        ->payoutsForImport($second, $this->actor)[0];

    expect(GatewaySettlementImport::query()->count())->toBe(2)
        ->and($second->rows()->where('match_state', 'duplicate')->count())->toBe(2)
        ->and($second->rows()->whereNotNull('duplicate_of_row_id')->count())->toBe(2)
        ->and($summary['contributing_import_ids'])->toBe([$first->id, $second->id])
        ->and($summary['row_count'])->toBe(4)
        ->and($summary['distinct_row_count'])->toBe(2)
        ->and($summary['duplicate_count'])->toBe(2)
        ->and($summary['gross_cents'])->toBe(427000)
        ->and($summary['commission_cents'])->toBe(9921)
        ->and($summary['settlement_fee_cents'])->toBe(600)
        ->and($summary['net_cents'])->toBe(416479)
        ->and($summary['blocking_reasons'])->toContain('SALE_UNMATCHED')
        ->and($summary['blocking_reasons'])->not->toContain('ECONOMIC_IDENTITY_CONFLICT');
});

it('blocks a reexport that changes financial content under the same identity', function (): void {
    $service = app(GatewaySettlementImportService::class);
    $service->stage(skipCashSettlementWorkbook(), $this->source, $this->actor);
    $conflicting = $service->stage(skipCashSettlementWorkbook([
        'sales' => '4271.00',
        'grossAmount' => '4271.00',
        'netMerchantSettlementAmount' => '4171.79',
    ], reverseRows: true), $this->source, $this->actor);
    $summary = app(GatewaySettlementReviewService::class)
        ->payoutsForImport($conflicting, $this->actor)[0];

    expect($conflicting->review_state)->toBe('blocked')
        ->and($conflicting->error_code)->toBe('ECONOMIC_IDENTITY_CONFLICT')
        ->and($conflicting->rows()->where('match_state', 'conflict')->count())->toBe(1)
        ->and($summary['blocking_reasons'])->toContain('ECONOMIC_IDENTITY_CONFLICT');
});

it('treats a changed transaction time as conflicting evidence for the same provider row', function (): void {
    $service = app(GatewaySettlementImportService::class);
    $original = $service->stage(skipCashSettlementWorkbook(), $this->source, $this->actor);
    $changed = $service->stage(
        skipCashSettlementWorkbook(['transactionTime' => '13:30:00']),
        $this->source,
        $this->actor,
    );

    $originalSale = $original->rows->firstWhere('order_type', 'sale');
    $changedSale = $changed->rows->firstWhere('order_type', 'sale');
    expect($changedSale->economic_identity)->toBe($originalSale->economic_identity)
        ->and($changedSale->financial_content_hash)->not->toBe($originalSale->financial_content_hash)
        ->and($changedSale->match_state)->toBe('conflict')
        ->and($changedSale->error_code)->toBe('ECONOMIC_IDENTITY_CONFLICT');
});

it('enforces the mutation flag and dedicated import permission', function (): void {
    $unauthorized = User::factory()->create(['status' => 'active']);

    expect(fn () => app(GatewaySettlementImportService::class)->stage(
        skipCashSettlementWorkbook(),
        $this->source,
        $unauthorized,
    ))->toThrow(AuthorizationException::class);

    config(['skipcash.settlements.enabled' => false]);

    expect(fn () => app(GatewaySettlementImportService::class)->stage(
        skipCashSettlementWorkbook(),
        $this->source,
        $this->actor,
    ))->toThrow(ValidationException::class);
});

it('creates the additive settlement schema and dedicated permissions', function (): void {
    expect(Schema::hasColumns('gateway_settlement_imports', [
        'payment_source_id', 'file_hash', 'gross_cents', 'commission_cents',
        'settlement_fee_cents', 'net_cents', 'review_snapshots', 'evidence_manifest',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('gateway_settlement_rows', [
            'physical_row', 'economic_identity', 'source_evidence', 'matched_provider_transaction_id',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('ar_clearing_settlements', [
            'payment_source_id', 'payout_reference', 'commission_cents', 'settlement_fee_cents', 'net_cents',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('payment_provider_transactions', 'active_clearing_settlement_id'))->toBeTrue()
        ->and(Permission::query()->whereIn('name', [
            'gateway_settlements.import',
            'gateway_settlements.review',
            'gateway_settlements.post',
            'gateway_settlements.void',
        ])->count())->toBe(4);
});

it('exposes scoped import review and private download endpoints', function (): void {
    $response = $this->actingAs($this->actor)->post(route('api.accounting.gateway-settlement-imports.store'), [
        'payment_source_id' => $this->source->id,
        'workbook' => skipCashSettlementWorkbook(),
    ]);

    $response->assertCreated()
        ->assertJsonPath('gross_cents', 427000)
        ->assertJsonPath('net_cents', 416479)
        ->assertJsonMissingPath('object_key');
    $importId = (int) $response->json('id');

    $this->getJson(route('api.accounting.gateway-settlement-imports.index'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $importId)
        ->assertJsonMissingPath('data.0.object_key');

    $this->getJson(route('api.accounting.gateway-settlement-imports.show', $importId))
        ->assertOk()
        ->assertJsonPath('rows.data.0.row_reference', '000123')
        ->assertJsonPath('rows.data.1.settlement_fee_cents', 600)
        ->assertJsonMissingPath('rows.data.0.customer_phone')
        ->assertJsonMissingPath('rows.data.0.source_evidence');

    $this->get(route('api.accounting.gateway-settlement-imports.file', $importId))
        ->assertOk()
        ->assertDownload('skipcash-settlement.xlsx');
});

it('denies settlement HTTP actions without the dedicated permission', function (): void {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)
        ->getJson(route('api.accounting.gateway-settlement-imports.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('api.accounting.gateway-settlement-imports.store'), [
            'payment_source_id' => $this->source->id,
            'workbook' => skipCashSettlementWorkbook(),
        ])
        ->assertForbidden();
});

it('renders the protected SkipCash report review without exposing customer phone evidence', function (): void {
    $import = app(GatewaySettlementImportService::class)->stage(
        skipCashSettlementWorkbook(),
        $this->source,
        $this->actor,
    );

    $this->actingAs($this->actor)
        ->get(route('accounting.ar-clearing.skipcash.show', $import))
        ->assertOk()
        ->assertSee('SkipCash report')
        ->assertSee('PAYOUT-0001')
        ->assertSee('4270.00')
        ->assertSee('4164.79')
        ->assertDontSee('+97455551234');

    $this->get(route('accounting.ar-clearing.skipcash.file', $import))
        ->assertOk()
        ->assertDownload('skipcash-settlement.xlsx');
});

it('stores and downloads private provider reference evidence without exposing its exact value', function (): void {
    $import = app(GatewaySettlementImportService::class)->stage(
        skipCashSettlementWorkbook(),
        $this->source,
        $this->actor,
    );
    $row = $import->rows->firstWhere('order_type', 'sale');
    $response = $this->actingAs($this->actor)->post(
        route('api.accounting.gateway-settlement-imports.evidence.store', $import),
        [
            'row_id' => $row->id,
            'provider_field' => 'provider_payment_id',
            'provider_value' => '000123',
            'import_revision' => $import->revision,
            'row_revision' => $row->revision,
            'evidence_file' => UploadedFile::fake()->createWithContent('provider-proof.pdf', "%PDF-1.4\nsynthetic proof"),
        ],
    );

    $response->assertCreated()
        ->assertJsonPath('purpose', 'provider_reference')
        ->assertJsonPath('provider_field', 'provider_payment_id')
        ->assertJsonMissingPath('provider_value')
        ->assertJsonMissingPath('object_key');
    $evidenceId = (string) $response->json('id');
    $manifest = $import->fresh()->evidence_manifest;
    expect($manifest[$evidenceId]['provider_value'])->toBe('000123')
        ->and($import->fresh()->getRawOriginal('evidence_manifest'))->not->toContain('000123');

    $this->get(route('accounting.ar-clearing.skipcash.evidence.file', [$import, $evidenceId]))
        ->assertOk()
        ->assertDownload('provider-proof.pdf');
});

it('stores private bank remittance evidence with exact payout values', function (): void {
    BankAccount::query()->where('company_id', $this->company->id)->update(['is_default' => false]);
    $bankLedger = LedgerAccount::factory()->create([
        'company_id' => $this->company->id,
        'code' => 'SKIP-REMITTANCE-BANK',
        'name' => 'SkipCash Remittance Bank',
        'type' => 'asset',
        'allow_direct_posting' => true,
    ]);
    $bank = BankAccount::factory()->create([
        'company_id' => $this->company->id,
        'ledger_account_id' => $bankLedger->id,
        'currency_code' => 'QAR',
        'is_active' => true,
        'is_default' => true,
    ]);
    $import = app(GatewaySettlementImportService::class)->stage(
        skipCashSettlementWorkbook(),
        $this->source,
        $this->actor,
    );

    $response = $this->actingAs($this->actor)->post(
        route('api.accounting.gateway-settlement-imports.evidence.store', $import),
        [
            'purpose' => 'bank_remittance',
            'payout_reference' => 'PAYOUT-0001',
            'amount_cents' => 416479,
            'bank_date' => '2026-09-01',
            'import_revision' => $import->revision,
            'evidence_file' => UploadedFile::fake()->createWithContent('remittance.pdf', "%PDF-1.4\nsynthetic remittance"),
        ],
    );

    $response->assertCreated()
        ->assertJsonPath('purpose', 'bank_remittance')
        ->assertJsonPath('bank_account_id', $bank->id)
        ->assertJsonPath('amount_cents', 416479)
        ->assertJsonPath('bank_date', '2026-09-01')
        ->assertJsonMissingPath('object_key');
    $evidenceId = (string) $response->json('id');

    $this->getJson(route('api.accounting.gateway-settlement-imports.show', $import))
        ->assertOk()
        ->assertJsonPath('evidence.0.purpose', 'bank_remittance')
        ->assertJsonPath('evidence.0.amount_cents', 416479)
        ->assertJsonMissingPath('evidence.0.object_key');
    $this->get(route('accounting.ar-clearing.skipcash.evidence.file', [$import, $evidenceId]))
        ->assertOk()
        ->assertDownload('remittance.pdf');
    expect(AccountingAuditLog::query()
        ->where('action', 'gateway_settlement_evidence.file_accessed')
        ->latest('id')
        ->firstOrFail()
        ->payload['purpose'])->toBe('bank_remittance');
});

it('denies the SkipCash report page without review permission', function (): void {
    $import = app(GatewaySettlementImportService::class)->stage(
        skipCashSettlementWorkbook(),
        $this->source,
        $this->actor,
    );
    $user = User::factory()->create(['status' => 'active']);
    $user->givePermissionTo('finance.access');

    $this->actingAs($user)
        ->get(route('accounting.ar-clearing.skipcash.show', $import))
        ->assertForbidden();
});

it('does not expose an import when the reviewer lacks one of its branches', function (): void {
    $import = app(GatewaySettlementImportService::class)->stage(
        skipCashSettlementWorkbook(),
        $this->source,
        $this->actor,
    );
    $reviewer = User::factory()->create(['status' => 'active']);
    $reviewer->givePermissionTo('gateway_settlements.review');

    $this->actingAs($reviewer)
        ->getJson(route('api.accounting.gateway-settlement-imports.show', $import))
        ->assertNotFound();

    $this->getJson(route('api.accounting.gateway-settlement-imports.index'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('does not expose a combined payout when another contributing import has an unavailable branch', function (): void {
    $first = app(GatewaySettlementImportService::class)->stage(
        skipCashSettlementWorkbook(),
        $this->source,
        $this->actor,
    );
    $otherBranch = Branch::query()->create([
        'company_id' => $this->company->id,
        'name' => 'Other Settlement Branch',
        'code' => 'SETTLEMENT-OTHER-1',
        'is_active' => true,
    ]);
    config(['skipcash.report_profiles.skipcash.branch_map' => [
        '1' => $this->branch->id,
        '2' => $otherBranch->id,
    ]]);
    app(GatewaySettlementImportService::class)->stage(
        skipCashSettlementWorkbook(['branch' => '2'], ['branch' => '2'], true),
        $this->source,
        $this->actor,
    );
    $reviewer = User::factory()->create(['status' => 'active']);
    $reviewer->givePermissionTo(['finance.access', 'gateway_settlements.review']);
    $reviewer->branches()->attach($this->branch->id);

    $this->actingAs($reviewer)
        ->getJson(route('api.accounting.gateway-settlement-imports.show', $first))
        ->assertForbidden();
});

it('scopes SkipCash outstanding totals to the reviewers visible branches', function (): void {
    $otherBranch = Branch::query()->create([
        'company_id' => $this->company->id,
        'name' => 'Other Settlement Branch',
        'code' => 'SETTLEMENT-OTHER-2',
        'is_active' => true,
    ]);
    Payment::factory()->create([
        'branch_id' => $this->branch->id,
        'company_id' => $this->company->id,
        'payment_source_id' => $this->source->id,
        'source' => 'ar',
        'method' => 'skipcash',
        'amount_cents' => 1000,
    ]);
    Payment::factory()->create([
        'branch_id' => $otherBranch->id,
        'company_id' => $this->company->id,
        'payment_source_id' => $this->source->id,
        'source' => 'ar',
        'method' => 'skipcash',
        'amount_cents' => 2000,
    ]);

    $summary = app(UnsettledIncomingReceiptsReportService::class)
        ->summary($this->company->id, now()->toDateString(), [$this->branch->id]);

    expect($summary['skipcash_pending_gross_cents'])->toBe(1000)
        ->and($summary['skipcash_unsettled_gross_cents'])->toBe(1000);
});

it('uses phone and report date only to rank verified settlement candidates', function (): void {
    $matchingCustomer = Customer::factory()->create([
        'phone' => '5555 1234',
        'phone_e164' => '+97455551234',
    ]);
    $otherCustomer = Customer::factory()->create([
        'phone' => '5555 9999',
        'phone_e164' => '+97455559999',
    ]);

    $makeCandidate = function (Customer $customer, string $providerId, string $finishedAt, string $verifiedAt): PaymentProviderTransaction {
        $payment = Payment::query()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'company_id' => $this->company->id,
            'payment_source_id' => $this->source->id,
            'client_uuid' => (string) Str::uuid(),
            'source' => 'ar',
            'method' => 'skipcash',
            'amount_cents' => 427000,
            'currency' => 'QAR',
            'received_at' => $finishedAt,
            'reference' => $providerId,
            'created_by' => $this->actor->id,
        ]);
        $attempt = PaymentCheckoutAttempt::query()->create([
            'reference' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'portal_user_id' => User::factory()->create(['customer_id' => $customer->id])->id,
            'payment_source_id' => $this->source->id,
            'client_uuid' => (string) Str::uuid(),
            'purpose' => 'ordinary_order',
            'currency' => 'QAR',
            'gross_amount_cents' => 427000,
            'discount_amount_cents' => 0,
            'payable_amount_cents' => 427000,
            'cart_fingerprint' => hash('sha256', 'cart-'.$providerId),
            'quote_fingerprint' => hash('sha256', 'quote-'.$providerId),
            'request_fingerprint' => hash('sha256', 'request-'.$providerId),
            'recovery_fingerprint' => hash('sha256', 'recovery-'.$providerId),
            'state' => 'completed',
            'started_at' => $finishedAt,
            'expires_at' => $verifiedAt,
            'completed_at' => $verifiedAt,
            'cart_snapshot' => ['synthetic' => true],
            'customer_snapshot' => ['customer_id' => $customer->id],
            'pricing_snapshot' => ['total_cents' => 427000],
            'terms_snapshot' => ['version' => 'v1'],
            'request_snapshot' => ['purpose' => 'ordinary_order'],
            'source_account_snapshot' => ['clearing_account_id' => $this->source->clearing_account_id],
            'notification_dispatch' => [],
            'provider_request_uuid' => (string) Str::uuid(),
            'provider_create_outcome' => 'created',
        ]);

        return PaymentProviderTransaction::query()->create([
            'attempt_id' => $attempt->id,
            'payment_source_id' => $this->source->id,
            'provider_payment_id' => $providerId,
            'merchant_transaction_id' => 'MERCHANT-'.$providerId,
            'amount_cents' => 427000,
            'currency' => 'QAR',
            'raw_status' => '2',
            'normalized_status' => 'paid',
            'finished_at' => $finishedAt,
            'finished_at_evidence_source' => 'details',
            'verified_paid_at' => $verifiedAt,
            'verified_amount_cents' => 427000,
            'verified_currency' => 'QAR',
            'verified_finished_at' => $finishedAt,
            'details_checked_at' => $verifiedAt,
            'classification' => 'purchase',
            'receipt_date' => substr($finishedAt, 0, 10),
            'receipt_client_uuid' => (string) Str::uuid(),
            'payment_id' => $payment->id,
        ]);
    };

    $matching = $makeCandidate($matchingCustomer, 'MATCHING-SUPPORT', '2026-08-31 09:30:00', '2026-08-31 09:31:00');
    $newer = $makeCandidate($otherCustomer, 'NEWER-UNRELATED', '2026-09-02 09:30:00', '2026-09-02 09:31:00');
    $import = app(GatewaySettlementImportService::class)->stage(
        skipCashSettlementWorkbook(),
        $this->source,
        $this->actor,
    );
    $row = $import->rows->firstWhere('order_type', 'sale');
    config(['skipcash.report_profiles.skipcash.identifier_mapping' => []]);

    $candidates = app(GatewaySettlementReviewService::class)->candidatesForRow($row, $this->actor);

    expect($candidates->pluck('id')->all())->toBe([$matching->id, $newer->id])
        ->and($candidates->first()->settlement_candidate_reasons)->toBe(['phone', 'date'])
        ->and(fn () => app(GatewaySettlementReviewService::class)->match(
            $import,
            $row,
            $matching->id,
            $import->revision,
            $row->revision,
            $this->actor,
        ))->toThrow(ValidationException::class);
});

it('matches reviews posts reconciles and voids one exact SkipCash payout', function (): void {
    $originalClearingAccountId = (int) $this->source->clearing_account_id;
    $customer = Customer::factory()->create();
    $portalUser = User::factory()->create([
        'customer_id' => $customer->id,
        'status' => 'active',
    ]);
    $payment = Payment::query()->create([
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'company_id' => $this->company->id,
        'payment_source_id' => $this->source->id,
        'client_uuid' => (string) Str::uuid(),
        'source' => 'ar',
        'method' => 'skipcash',
        'amount_cents' => 427000,
        'currency' => 'QAR',
        'received_at' => '2026-08-31 12:30:00',
        'reference' => '000123',
        'created_by' => $this->actor->id,
    ]);
    $receiptEntry = app(SubledgerService::class)->recordArPaymentReceived(
        $payment,
        427000,
        0,
        $this->actor->id,
        $this->source->clearing_account_id,
    );
    expect($receiptEntry)->not->toBeNull();

    $attempt = PaymentCheckoutAttempt::query()->create([
        'reference' => (string) Str::uuid(),
        'company_id' => $this->company->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $customer->id,
        'portal_user_id' => $portalUser->id,
        'payment_source_id' => $this->source->id,
        'client_uuid' => (string) Str::uuid(),
        'purpose' => 'ordinary_order',
        'currency' => 'QAR',
        'gross_amount_cents' => 427000,
        'discount_amount_cents' => 0,
        'payable_amount_cents' => 427000,
        'cart_fingerprint' => str_repeat('a', 64),
        'quote_fingerprint' => str_repeat('b', 64),
        'request_fingerprint' => str_repeat('c', 64),
        'recovery_fingerprint' => str_repeat('d', 64),
        'state' => 'completed',
        'started_at' => '2026-08-31 09:25:00',
        'expires_at' => '2026-08-31 09:40:00',
        'completed_at' => '2026-08-31 09:30:00',
        'cart_snapshot' => ['synthetic' => true],
        'customer_snapshot' => ['phone' => '+97455551234'],
        'pricing_snapshot' => ['total_cents' => 427000],
        'terms_snapshot' => ['version' => 'v1'],
        'request_snapshot' => ['purpose' => 'ordinary_order'],
        'source_account_snapshot' => ['clearing_account_id' => $this->source->clearing_account_id],
        'notification_dispatch' => [],
        'provider_request_uuid' => (string) Str::uuid(),
        'provider_create_outcome' => 'created',
    ]);
    $providerTransaction = PaymentProviderTransaction::query()->create([
        'attempt_id' => $attempt->id,
        'payment_source_id' => $this->source->id,
        'provider_payment_id' => '000123',
        'merchant_transaction_id' => 'MERCHANT-000123',
        'amount_cents' => 427000,
        'currency' => 'QAR',
        'raw_status' => '2',
        'normalized_status' => 'paid',
        'finished_at' => '2026-08-31 09:30:00',
        'finished_at_evidence_source' => 'details',
        'verified_paid_at' => '2026-08-31 09:31:00',
        'verified_amount_cents' => 427000,
        'verified_currency' => 'QAR',
        'verified_finished_at' => '2026-08-31 09:30:00',
        'details_checked_at' => '2026-08-31 09:31:00',
        'classification' => 'purchase',
        'receipt_date' => '2026-08-31',
        'receipt_client_uuid' => (string) Str::uuid(),
        'payment_id' => $payment->id,
    ]);
    $replacementClearing = LedgerAccount::factory()->create([
        'company_id' => $this->company->id,
        'code' => 'SKIPCASH-NEW-CLEARING',
        'name' => 'SkipCash New Clearing',
        'type' => 'asset',
        'is_active' => true,
        'allow_direct_posting' => true,
    ]);
    $this->source->update(['clearing_account_id' => $replacementClearing->id]);

    $outstandingBeforeSettlement = app(UnsettledIncomingReceiptsReportService::class)
        ->summary($this->company->id, '2026-09-01');
    expect($outstandingBeforeSettlement['skipcash_pending_gross_cents'])->toBe(427000)
        ->and($outstandingBeforeSettlement['skipcash_correction_required_gross_cents'])->toBe(0);

    BankAccount::query()->where('company_id', $this->company->id)->update(['is_default' => false]);
    $bankLedger = LedgerAccount::factory()->create([
        'company_id' => $this->company->id,
        'code' => 'SKIP-BANK-TEST',
        'name' => 'SkipCash Test Bank',
        'type' => 'asset',
        'allow_direct_posting' => true,
    ]);
    $bank = BankAccount::factory()->create([
        'company_id' => $this->company->id,
        'ledger_account_id' => $bankLedger->id,
        'currency_code' => 'QAR',
        'is_active' => true,
        'is_default' => true,
        'opening_balance' => 0,
    ]);
    $commissionExpense = LedgerAccount::factory()->create([
        'company_id' => $this->company->id,
        'code' => 'SKIP-COMMISSION-TEST',
        'name' => 'SkipCash Commission Expense',
        'type' => 'expense',
        'allow_direct_posting' => true,
    ]);
    $settlementFeeExpense = LedgerAccount::factory()->create([
        'company_id' => $this->company->id,
        'code' => 'SKIP-FEE-TEST',
        'name' => 'SkipCash Settlement Fee Expense',
        'type' => 'expense',
        'allow_direct_posting' => true,
    ]);
    AccountingAccountMapping::query()->updateOrCreate(
        ['company_id' => $this->company->id, 'mapping_key' => 'skipcash_commission_expense'],
        ['ledger_account_id' => $commissionExpense->id],
    );
    AccountingAccountMapping::query()->updateOrCreate(
        ['company_id' => $this->company->id, 'mapping_key' => 'skipcash_settlement_fee_expense'],
        ['ledger_account_id' => $settlementFeeExpense->id],
    );
    $statementImport = BankStatementImport::query()->create([
        'bank_account_id' => $bank->id,
        'company_id' => $this->company->id,
        'file_name' => 'synthetic-bank.csv',
        'storage_path' => 'testing/synthetic-bank.csv',
        'imported_rows' => 1,
        'status' => 'processed',
        'processed_at' => now(),
        'uploaded_by' => $this->actor->id,
    ]);
    $bankEvidence = BankTransaction::query()->create([
        'company_id' => $this->company->id,
        'bank_account_id' => $bank->id,
        'transaction_type' => 'statement',
        'transaction_date' => '2026-09-01',
        'amount' => '4164.79',
        'direction' => 'inflow',
        'status' => 'open',
        'is_cleared' => false,
        'reference' => 'PAYOUT-0001',
        'source_type' => null,
        'source_id' => null,
        'statement_import_id' => $statementImport->id,
    ]);

    $import = app(GatewaySettlementImportService::class)->stage(
        skipCashSettlementWorkbook(),
        $this->source,
        $this->actor,
    );
    $reviews = app(GatewaySettlementReviewService::class);
    $saleRow = $import->rows->firstWhere('order_type', 'sale');
    config(['skipcash.report_profiles.skipcash.identifier_mapping' => []]);
    $evidence = app(GatewaySettlementEvidenceService::class)->storeProviderReference(
        $import,
        $saleRow,
        UploadedFile::fake()->createWithContent('controlled-provider-proof.pdf', "%PDF-1.4\nsynthetic controlled proof"),
        'provider_payment_id',
        '000123',
        $import->revision,
        $saleRow->revision,
        $this->actor,
    );
    $import->refresh();
    $saleRow->refresh();
    $matched = $reviews->match(
        $import,
        $saleRow,
        $providerTransaction->id,
        $import->revision,
        $saleRow->revision,
        $this->actor,
        $evidence['id'],
    );
    expect($matched->match_state)->toBe('matched');

    $reexport = app(GatewaySettlementImportService::class)->stage(
        skipCashSettlementWorkbook(reverseRows: true),
        $this->source,
        $this->actor,
    );
    $summary = $reviews->payoutSummary($this->source->id, 'PAYOUT-0001');
    expect($summary['blocking_reasons'])->toBe([])
        ->and($summary['row_count'])->toBe(4)
        ->and($summary['distinct_row_count'])->toBe(2)
        ->and($summary['duplicate_count'])->toBe(2);
    $unrelatedDeposit = BankTransaction::query()->create([
        'company_id' => $this->company->id,
        'bank_account_id' => $bank->id,
        'transaction_type' => 'statement',
        'transaction_date' => '2026-09-01',
        'amount' => '4164.79',
        'direction' => 'inflow',
        'status' => 'open',
        'is_cleared' => false,
        'reference' => 'UNRELATED-DEPOSIT',
        'source_type' => null,
        'source_id' => null,
        'statement_import_id' => $statementImport->id,
    ]);
    expect(fn () => $reviews->review(
        $reexport,
        'PAYOUT-0001',
        $summary['payout_fingerprint'],
        $unrelatedDeposit->id,
        $reexport->revision,
        $this->actor,
    ))->toThrow(ValidationException::class);

    $reconciliation = app(BankReconciliationService::class)->reconcile($bank, [
        'statement_date' => '2026-09-01',
        'statement_ending_balance' => 8329.58,
        'statement_import_id' => $statementImport->id,
    ], $this->actor->id);
    expect($reconciliation['matched_count'])->toBe(0)
        ->and($reconciliation['unmatched_count'])->toBe(2);

    $remittance = app(GatewaySettlementEvidenceService::class)->storeBankRemittance(
        $reexport,
        UploadedFile::fake()->createWithContent('controlled-remittance.pdf', "%PDF-1.4\nsynthetic controlled remittance"),
        'PAYOUT-0001',
        416479,
        '2026-09-01',
        $reexport->revision,
        $this->actor,
    );
    $reexport->refresh();
    $remittanceOnlyReview = $reviews->review(
        $reexport,
        'PAYOUT-0001',
        $summary['payout_fingerprint'],
        null,
        $reexport->revision,
        $this->actor,
        $remittance['id'],
    );
    expect($remittanceOnlyReview['evidence_bank_transaction_id'])->toBeNull()
        ->and($remittanceOnlyReview['settlement_date'])->toBe('2026-09-01');

    $review = $reviews->review(
        $reexport,
        'PAYOUT-0001',
        $summary['payout_fingerprint'],
        $bankEvidence->id,
        $remittanceOnlyReview['import_revision'],
        $this->actor,
        $remittance['id'],
    );
    $postUuid = (string) Str::uuid();
    $posting = app(GatewaySettlementPostingService::class);
    $settlement = $posting->post(
        $reexport->fresh(),
        'PAYOUT-0001',
        $review['reviewed_fingerprint'],
        $postUuid,
        $this->actor,
    );

    $replayed = $posting->post(
        $reexport->fresh(),
        'PAYOUT-0001',
        $review['reviewed_fingerprint'],
        $postUuid,
        $this->actor,
    );
    expect($replayed->id)->toBe($settlement->id)
        ->and(\App\Models\ArClearingSettlement::query()->count())->toBe(1)
        ->and(SubledgerEntry::query()
            ->where('source_type', 'ar_clearing_settlement')
            ->where('source_id', $settlement->id)
            ->where('event', 'settle')
            ->count())->toBe(1)
        ->and(BankTransaction::query()
            ->where('source_type', \App\Models\ArClearingSettlement::class)
            ->where('source_id', $settlement->id)
            ->count())->toBe(1);
    expect(fn () => $posting->post(
        $reexport->fresh(),
        'PAYOUT-0001',
        str_repeat('f', 64),
        $postUuid,
        $this->actor,
    ))->toThrow(GatewaySettlementConflictException::class);

    expect($settlement->settlement_method)->toBe('skipcash')
        ->and($settlement->amount_cents)->toBe(427000)
        ->and($settlement->commission_cents)->toBe(9921)
        ->and($settlement->settlement_fee_cents)->toBe(600)
        ->and($settlement->net_cents)->toBe(416479)
        ->and($settlement->evidence_bank_transaction_id)->toBe($bankEvidence->id)
        ->and($settlement->evidence_snapshot['type'])->toBe('bank_statement')
        ->and($settlement->items)->toHaveCount(1)
        ->and($settlement->adjustments)->toHaveCount(2)
        ->and($import->fresh()->posting_state)->toBe('posted')
        ->and($reexport->fresh()->posting_state)->toBe('posted')
        ->and((int) ArClearingSettlementAdjustment::query()->sum('amount_cents'))->toBe(10521)
        ->and($payment->fresh()->clearing_settled_at)->not->toBeNull()
        ->and($providerTransaction->fresh()->active_clearing_settlement_id)->toBe($settlement->id)
        ->and($payment->fresh()->allocations()->count())->toBe(0);

    $outstandingAfterSettlement = app(UnsettledIncomingReceiptsReportService::class)
        ->summary($this->company->id, '2026-09-01');
    expect($outstandingAfterSettlement['skipcash_unsettled_gross_cents'])->toBe(0);

    $this->actingAs($this->actor)
        ->get(route('accounting.ar-clearing-show', $settlement))
        ->assertOk()
        ->assertSee('SkipCash payout clearing')
        ->assertSee('Gross sales')
        ->assertSee('4270.00')
        ->assertSee('99.21')
        ->assertSee('6.00')
        ->assertSee('4164.79')
        ->assertSee('CR SkipCash clearing');

    $settlementEntry = SubledgerEntry::query()
        ->with('lines')
        ->where('source_type', 'ar_clearing_settlement')
        ->where('source_id', $settlement->id)
        ->where('event', 'settle')
        ->firstOrFail();
    expect($settlementEntry->lines)->toHaveCount(4)
        ->and(gatewayLedgerCents($settlementEntry, $bankLedger->id, 'debit'))->toBe(416479)
        ->and(gatewayLedgerCents($settlementEntry, $commissionExpense->id, 'debit'))->toBe(9921)
        ->and(gatewayLedgerCents($settlementEntry, $settlementFeeExpense->id, 'debit'))->toBe(600)
        ->and(gatewayLedgerCents($settlementEntry, $originalClearingAccountId, 'credit'))->toBe(427000)
        ->and(gatewayLedgerCents($settlementEntry, $replacementClearing->id, 'credit'))->toBe(0);

    $bookTransaction = BankTransaction::query()
        ->where('source_type', \App\Models\ArClearingSettlement::class)
        ->where('source_id', $settlement->id)
        ->whereNull('statement_import_id')
        ->firstOrFail();
    expect($bookTransaction->amount)->toBe('4164.79');

    expect(fn () => app(BankReconciliationService::class)->match(
        $reconciliation['run'],
        $unrelatedDeposit->id,
        $bookTransaction->id,
        $this->actor->id,
    ))->toThrow(ValidationException::class);
    app(BankReconciliationService::class)->match(
        $reconciliation['run'],
        $bankEvidence->id,
        $bookTransaction->id,
        $this->actor->id,
    );
    expect($bankEvidence->fresh()->matched_bank_transaction_id)->toBe($bookTransaction->id)
        ->and($bookTransaction->fresh()->matched_bank_transaction_id)->toBe($bankEvidence->id);

    expect(fn () => app(ArClearingSettlementService::class)->void($settlement, $this->actor->id, 'Correction'))
        ->toThrow(ValidationException::class);

    app(BankReconciliationService::class)->unmatch(
        $reconciliation['run'],
        $bankEvidence->id,
        $this->actor->id,
    );
    app(ArClearingSettlementService::class)->void($settlement, $this->actor->id, 'Correction');

    expect($settlement->fresh()->voided_at)->not->toBeNull()
        ->and($bookTransaction->fresh()->status)->toBe('void')
        ->and($payment->fresh()->clearing_settled_at)->toBeNull()
        ->and($payment->fresh()->voided_at)->toBeNull()
        ->and($providerTransaction->fresh()->active_clearing_settlement_id)->toBeNull()
        ->and($settlement->items()->count())->toBe(1)
        ->and($settlement->adjustments()->count())->toBe(2)
        ->and(SubledgerEntry::query()
            ->where('source_type', 'ar_clearing_settlement')
            ->where('source_id', $settlement->id)
            ->where('event', 'void')
            ->exists())->toBeTrue();

    $outstandingAfterVoid = app(UnsettledIncomingReceiptsReportService::class)
        ->summary($this->company->id, now()->toDateString());
    expect($outstandingAfterVoid['skipcash_pending_gross_cents'])->toBe(0)
        ->and($outstandingAfterVoid['skipcash_correction_required_gross_cents'])->toBe(427000)
        ->and($outstandingAfterVoid['skipcash_unsettled_gross_cents'])->toBe(427000);
});

/**
 * @param  array<string, string>  $saleOverrides
 * @param  array<string, string>  $feeOverrides
 */
function skipCashSettlementWorkbook(
    array $saleOverrides = [],
    array $feeOverrides = [],
    bool $reverseRows = false,
): UploadedFile {
    $headers = [
        'period', 'paymentRef', 'referenceNumber', 'orderType', 'status',
        'transactionDate', 'transactionTime', 'merchant', 'branch', 'orderId',
        'customerPhoneNumber', 'sales', 'grossAmount', 'variableCommission',
        'fixedCommission', 'totalCommission', 'netMerchantSettlementAmount',
    ];
    $branchCode = '1';
    $sale = array_replace([
        'period' => '01/08/2026 - 31/08/2026',
        'paymentRef' => 'PAYOUT-0001',
        'referenceNumber' => '000123',
        'orderType' => 'Sale',
        'status' => 'Successful',
        'transactionDate' => '31-Aug-2026',
        'transactionTime' => '12:30:00',
        'merchant' => 'Layla Kitchen',
        'branch' => $branchCode,
        'orderId' => 'Null',
        'customerPhoneNumber' => '5555 1234',
        'sales' => '4270.00',
        'grossAmount' => '4270.00',
        'variableCommission' => '98.21',
        'fixedCommission' => '1.00',
        'totalCommission' => '99.21',
        'netMerchantSettlementAmount' => '4170.79',
    ], $saleOverrides);
    $fee = array_replace([
        'period' => '01/08/2026 - 31/08/2026',
        'paymentRef' => 'PAYOUT-0001',
        'referenceNumber' => 'FEE-0001',
        'orderType' => 'Settlement Fee',
        'status' => 'Successful',
        'transactionDate' => '01-Sep-2026',
        'transactionTime' => '08:00:00',
        'merchant' => 'Layla Kitchen',
        'branch' => $branchCode,
        'orderId' => 'Null',
        'customerPhoneNumber' => 'Null',
        'sales' => '0.00',
        'grossAmount' => '0.00',
        'variableCommission' => '6.00',
        'fixedCommission' => '0.00',
        'totalCommission' => '6.00',
        'netMerchantSettlementAmount' => '-6.00',
    ], $feeOverrides);

    $dataRows = $reverseRows ? [$fee, $sale] : [$sale, $fee];
    $rows = skipCashXmlRow($headers, 1, true);
    foreach ($dataRows as $index => $dataRow) {
        $rows .= skipCashXmlRow(array_values($dataRow), $index + 2);
    }
    $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>';
    $path = tempnam(sys_get_temp_dir(), 'skipcash-settlement-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    return new UploadedFile(
        $path,
        'skipcash-settlement.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );
}

/** @param array<int, string> $values */
function skipCashXmlRow(array $values, int $row, bool $headers = false): string
{
    $cells = '';
    foreach ($values as $index => $value) {
        $column = chr(65 + $index);
        $escaped = htmlspecialchars((string) $value, ENT_XML1);
        $numeric = ! $headers && preg_match('/^-?\d+(?:\.\d+)?$/', (string) $value) === 1;
        $cells .= $numeric
            ? '<c r="'.$column.$row.'"><v>'.$escaped.'</v></c>'
            : '<c r="'.$column.$row.'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>';
    }

    return '<row r="'.$row.'">'.$cells.'</row>';
}

function gatewayLedgerCents(SubledgerEntry $entry, int $accountId, string $side): int
{
    $amount = (string) $entry->lines->firstWhere('account_id', $accountId)?->{$side};

    return (int) round((float) $amount * 100);
}

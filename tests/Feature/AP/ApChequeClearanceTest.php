<?php

use App\Models\AccountingCompany;
use App\Models\ApChequeClearance;
use App\Models\ApPayment;
use App\Models\BankAccount;
use App\Models\LedgerAccount;
use App\Models\SubledgerEntry;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AP\ApChequeClearanceService;
use App\Services\AP\ApPaymentVoidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->user = User::factory()->create(['status' => 'active']);
    $this->user->assignRole('admin');

    $this->company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $this->supplier = Supplier::factory()->create();

    $this->bankLedgerAccount = LedgerAccount::factory()->create([
        'company_id' => $this->company->id,
        'code'       => '1011-test',
        'name'       => 'Test Bank (AP)',
        'type'       => 'asset',
    ]);

    $this->bankAccount = BankAccount::factory()->create([
        'company_id'        => $this->company->id,
        'ledger_account_id' => $this->bankLedgerAccount->id,
        'is_active'         => true,
        'is_default'        => false,
    ]);
});

function makeApChequePayment(float $amount = 100.00): ApPayment
{
    return ApPayment::factory()->create([
        'supplier_id'    => test()->supplier->id,
        'company_id'     => test()->company->id,
        'payment_method' => 'cheque',
        'amount'         => $amount,
        'voided_at'      => null,
        'cheque_cleared_at' => null,
    ]);
}

it('posts correct DR issued_cheques_clearing / CR bank subledger entry on clearance', function () {
    $payment = makeApChequePayment(200.00);
    $svc = app(ApChequeClearanceService::class);

    $clearance = $svc->clear(
        apPaymentId:   $payment->id,
        bankAccountId: $this->bankAccount->id,
        clearanceDate: now()->toDateString(),
        amount:        200.00,
        actorId:       $this->user->id,
    );

    $entry = SubledgerEntry::where('source_type', 'ap_cheque_clearance')
        ->where('source_id', $clearance->id)
        ->where('event', 'clear')
        ->firstOrFail();

    $lines = $entry->lines;
    expect($lines)->toHaveCount(2);

    $debitLine  = $lines->firstWhere('debit', '>', 0);
    $creditLine = $lines->firstWhere('credit', '>', 0);

    // Debit line is issued_cheques_clearing (not bank), credit line is bank.
    expect($debitLine->account_id)->not->toBe($this->bankLedgerAccount->id)
        ->and((float) $debitLine->debit)->toBe(200.0)
        ->and($creditLine->account_id)->toBe($this->bankLedgerAccount->id)
        ->and((float) $creditLine->credit)->toBe(200.0);
});

it('marks ap_payment cheque_cleared_at non-null after clearance', function () {
    $payment = makeApChequePayment(150.00);
    $svc = app(ApChequeClearanceService::class);

    $svc->clear(
        apPaymentId:   $payment->id,
        bankAccountId: $this->bankAccount->id,
        clearanceDate: now()->toDateString(),
        amount:        150.00,
        actorId:       $this->user->id,
    );

    expect(ApPayment::find($payment->id)->cheque_cleared_at)->not->toBeNull();
});

it('throws if the ap_payment method is not cheque', function () {
    $payment = ApPayment::factory()->create([
        'supplier_id'    => $this->supplier->id,
        'company_id'     => $this->company->id,
        'payment_method' => 'bank_transfer',
        'amount'         => 100.00,
    ]);

    $svc = app(ApChequeClearanceService::class);
    expect(fn () => $svc->clear(
        apPaymentId:   $payment->id,
        bankAccountId: $this->bankAccount->id,
        clearanceDate: now()->toDateString(),
        amount:        100.00,
        actorId:       $this->user->id,
    ))->toThrow(ValidationException::class);
});

it('throws if the ap_payment is already cleared', function () {
    $payment = makeApChequePayment(100.00);
    $payment->cheque_cleared_at = now();
    $payment->save();

    $svc = app(ApChequeClearanceService::class);
    expect(fn () => $svc->clear(
        apPaymentId:   $payment->id,
        bankAccountId: $this->bankAccount->id,
        clearanceDate: now()->toDateString(),
        amount:        100.00,
        actorId:       $this->user->id,
    ))->toThrow(ValidationException::class);
});

it('throws if the ap_payment is voided', function () {
    $payment = makeApChequePayment(100.00);
    $payment->voided_at = now();
    $payment->save();

    $svc = app(ApChequeClearanceService::class);
    expect(fn () => $svc->clear(
        apPaymentId:   $payment->id,
        bankAccountId: $this->bankAccount->id,
        clearanceDate: now()->toDateString(),
        amount:        100.00,
        actorId:       $this->user->id,
    ))->toThrow(ValidationException::class);
});

it('voiding a clearance reverses the subledger entry and resets cheque_cleared_at', function () {
    $payment = makeApChequePayment(300.00);
    $svc = app(ApChequeClearanceService::class);

    $clearance = $svc->clear(
        apPaymentId:   $payment->id,
        bankAccountId: $this->bankAccount->id,
        clearanceDate: now()->toDateString(),
        amount:        300.00,
        actorId:       $this->user->id,
    );

    $svc->void($clearance, $this->user->id);

    $reversal = SubledgerEntry::where('source_type', 'ap_cheque_clearance')
        ->where('source_id', $clearance->id)
        ->where('event', 'void')
        ->first();

    expect($reversal)->not->toBeNull()
        ->and(ApPayment::find($payment->id)->cheque_cleared_at)->toBeNull();
});

it('cannot void an already-voided clearance', function () {
    $payment = makeApChequePayment(100.00);
    $svc = app(ApChequeClearanceService::class);

    $clearance = $svc->clear(
        apPaymentId:   $payment->id,
        bankAccountId: $this->bankAccount->id,
        clearanceDate: now()->toDateString(),
        amount:        100.00,
        actorId:       $this->user->id,
    );

    $svc->void($clearance, $this->user->id);
    $clearance->refresh();

    expect(fn () => $svc->void($clearance, $this->user->id))
        ->toThrow(ValidationException::class);
});

it('cannot void an AP cheque payment that has an active clearance', function () {
    $payment = makeApChequePayment(100.00);
    $svc = app(ApChequeClearanceService::class);

    $svc->clear(
        apPaymentId:   $payment->id,
        bankAccountId: $this->bankAccount->id,
        clearanceDate: now()->toDateString(),
        amount:        100.00,
        actorId:       $this->user->id,
    );

    $voidSvc = app(ApPaymentVoidService::class);
    expect(fn () => $voidSvc->void($payment, $this->user->id))
        ->toThrow(ValidationException::class);
});

it('idempotency: calling clear with same client_uuid returns existing clearance', function () {
    $payment = makeApChequePayment(100.00);
    $svc = app(ApChequeClearanceService::class);
    $uuid = (string) \Illuminate\Support\Str::uuid();

    $c1 = $svc->clear(
        apPaymentId:   $payment->id,
        bankAccountId: $this->bankAccount->id,
        clearanceDate: now()->toDateString(),
        amount:        100.00,
        actorId:       $this->user->id,
        clientUuid:    $uuid,
    );

    $c2 = $svc->clear(
        apPaymentId:   $payment->id,
        bankAccountId: $this->bankAccount->id,
        clearanceDate: now()->toDateString(),
        amount:        100.00,
        actorId:       $this->user->id,
        clientUuid:    $uuid,
    );

    expect($c1->id)->toBe($c2->id)
        ->and(ApChequeClearance::count())->toBe(1);
});

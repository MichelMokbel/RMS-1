<?php

use App\Models\AccountingCompany;
use App\Models\ArClearingSettlement;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\SubledgerEntry;
use App\Models\User;
use App\Services\AR\ArClearingSettlementService;
use App\Services\AR\ArPaymentDeleteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->user = User::factory()->create(['status' => 'active']);
    $this->user->assignRole('admin');

    $this->company = AccountingCompany::query()->where('is_default', true)->firstOrFail();

    // Ensure a ledger account exists for the bank account.
    $this->bankLedgerAccount = LedgerAccount::factory()->create([
        'company_id' => $this->company->id,
        'code'       => '1010-test',
        'name'       => 'Test Bank Account',
        'type'       => 'asset',
    ]);

    $this->bankAccount = BankAccount::factory()->create([
        'company_id'       => $this->company->id,
        'ledger_account_id'=> $this->bankLedgerAccount->id,
        'is_active'        => true,
        'is_default'       => false,
    ]);

    $this->customer = Customer::factory()->corporate()->create();
});

function makeArPayment(string $method, int $amountCents = 5000, ?int $companyId = null): Payment
{
    return Payment::factory()->create([
        'source'      => 'ar',
        'method'      => $method,
        'amount_cents'=> $amountCents,
        'company_id'  => $companyId ?? test()->company->id,
        'customer_id' => test()->customer->id,
        'voided_at'   => null,
        'clearing_settled_at' => null,
    ]);
}

it('posts correct DR bank / CR card_clearing subledger entry on card settlement', function () {
    $payment = makeArPayment('card', 10000);

    $svc = app(ArClearingSettlementService::class);
    $settlement = $svc->settle(
        paymentIds:     [$payment->id],
        method:         'card',
        bankAccountId:  $this->bankAccount->id,
        settlementDate: now()->toDateString(),
        actorId:        $this->user->id,
    );

    $entry = SubledgerEntry::where('source_type', 'ar_clearing_settlement')
        ->where('source_id', $settlement->id)
        ->where('event', 'settle')
        ->firstOrFail();

    $lines = $entry->lines;
    expect($lines)->toHaveCount(2);

    $debitLine  = $lines->firstWhere('debit', '>', 0);
    $creditLine = $lines->firstWhere('credit', '>', 0);

    expect($debitLine->account_id)->toBe($this->bankLedgerAccount->id)
        ->and((float) $debitLine->debit)->toBe(100.0);    // 10000 cents / 100

    // Credit line must be card_clearing, not the bank account.
    expect($creditLine->account_id)->not->toBe($this->bankLedgerAccount->id)
        ->and((float) $creditLine->credit)->toBe(100.0);
});

it('marks all settled payments clearing_settled_at non-null', function () {
    $p1 = makeArPayment('card', 3000);
    $p2 = makeArPayment('card', 7000);

    $svc = app(ArClearingSettlementService::class);
    $svc->settle(
        paymentIds:     [$p1->id, $p2->id],
        method:         'card',
        bankAccountId:  $this->bankAccount->id,
        settlementDate: now()->toDateString(),
        actorId:        $this->user->id,
    );

    expect(Payment::find($p1->id)->clearing_settled_at)->not->toBeNull()
        ->and(Payment::find($p2->id)->clearing_settled_at)->not->toBeNull();
});

it('throws if a payment method does not match the settlement method', function () {
    $cashPayment = makeArPayment('cash', 5000);

    $svc = app(ArClearingSettlementService::class);
    expect(fn () => $svc->settle(
        paymentIds:     [$cashPayment->id],
        method:         'card',
        bankAccountId:  $this->bankAccount->id,
        settlementDate: now()->toDateString(),
        actorId:        $this->user->id,
    ))->toThrow(ValidationException::class);
});

it('throws if a payment is already settled', function () {
    $payment = makeArPayment('card', 5000);
    $payment->clearing_settled_at = now();
    $payment->save();

    $svc = app(ArClearingSettlementService::class);
    expect(fn () => $svc->settle(
        paymentIds:     [$payment->id],
        method:         'card',
        bankAccountId:  $this->bankAccount->id,
        settlementDate: now()->toDateString(),
        actorId:        $this->user->id,
    ))->toThrow(ValidationException::class);
});

it('throws if a payment is voided', function () {
    $payment = makeArPayment('card', 5000);
    $payment->voided_at = now();
    $payment->save();

    $svc = app(ArClearingSettlementService::class);
    expect(fn () => $svc->settle(
        paymentIds:     [$payment->id],
        method:         'card',
        bankAccountId:  $this->bankAccount->id,
        settlementDate: now()->toDateString(),
        actorId:        $this->user->id,
    ))->toThrow(ValidationException::class);
});

it('voiding a settlement reverses the subledger entry', function () {
    $payment = makeArPayment('card', 8000);
    $svc = app(ArClearingSettlementService::class);

    $settlement = $svc->settle(
        paymentIds:     [$payment->id],
        method:         'card',
        bankAccountId:  $this->bankAccount->id,
        settlementDate: now()->toDateString(),
        actorId:        $this->user->id,
    );

    $svc->void($settlement, $this->user->id);

    $reversal = SubledgerEntry::where('source_type', 'ar_clearing_settlement')
        ->where('source_id', $settlement->id)
        ->where('event', 'void')
        ->first();

    expect($reversal)->not->toBeNull();
});

it('voiding a settlement clears clearing_settled_at on linked payments', function () {
    $payment = makeArPayment('card', 5000);
    $svc = app(ArClearingSettlementService::class);

    $settlement = $svc->settle(
        paymentIds:     [$payment->id],
        method:         'card',
        bankAccountId:  $this->bankAccount->id,
        settlementDate: now()->toDateString(),
        actorId:        $this->user->id,
    );

    $svc->void($settlement, $this->user->id);

    expect(Payment::find($payment->id)->clearing_settled_at)->toBeNull();
});

it('cannot void an already voided settlement', function () {
    $payment = makeArPayment('card', 5000);
    $svc = app(ArClearingSettlementService::class);

    $settlement = $svc->settle(
        paymentIds:     [$payment->id],
        method:         'card',
        bankAccountId:  $this->bankAccount->id,
        settlementDate: now()->toDateString(),
        actorId:        $this->user->id,
    );

    $svc->void($settlement, $this->user->id);
    $settlement->refresh();

    expect(fn () => $svc->void($settlement, $this->user->id))
        ->toThrow(ValidationException::class);
});

it('cannot void an AR payment that has an active clearing settlement', function () {
    $payment = makeArPayment('card', 5000);
    $svc = app(ArClearingSettlementService::class);

    $svc->settle(
        paymentIds:     [$payment->id],
        method:         'card',
        bankAccountId:  $this->bankAccount->id,
        settlementDate: now()->toDateString(),
        actorId:        $this->user->id,
    );

    $deleteSvc = app(ArPaymentDeleteService::class);
    expect(fn () => $deleteSvc->delete($payment, $this->user->id))
        ->toThrow(ValidationException::class);
});

it('idempotency: calling settle with same client_uuid returns existing settlement', function () {
    $payment = makeArPayment('card', 5000);
    $svc = app(ArClearingSettlementService::class);
    $uuid = (string) \Illuminate\Support\Str::uuid();

    $s1 = $svc->settle(
        paymentIds:     [$payment->id],
        method:         'card',
        bankAccountId:  $this->bankAccount->id,
        settlementDate: now()->toDateString(),
        actorId:        $this->user->id,
        clientUuid:     $uuid,
    );

    $s2 = $svc->settle(
        paymentIds:     [$payment->id],
        method:         'card',
        bankAccountId:  $this->bankAccount->id,
        settlementDate: now()->toDateString(),
        actorId:        $this->user->id,
        clientUuid:     $uuid,
    );

    expect($s1->id)->toBe($s2->id)
        ->and(ArClearingSettlement::count())->toBe(1);
});

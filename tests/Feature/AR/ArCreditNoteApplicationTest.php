<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\SubledgerEntry;
use App\Models\SubledgerLine;
use App\Models\User;
use App\Services\AR\ArAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->user = User::factory()->create(['status' => 'active']);
    $this->user->assignRole('admin');
});

it('creates a subledger entry when a credit note is applied to an invoice', function () {
    $customer = Customer::factory()->create();

    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-CN-001',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'total_cents' => 10000,
        'paid_total_cents' => 0,
        'balance_cents' => 10000,
    ]);

    // Credit notes carry a negative balance_cents; total_cents is negative.
    $creditNote = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'type' => 'credit_note',
        'status' => 'issued',
        'invoice_number' => 'CN-001',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'total_cents' => -5000,
        'paid_total_cents' => 0,
        'balance_cents' => -5000,
    ]);

    /** @var ArAllocationService $svc */
    $svc = app(ArAllocationService::class);

    $svc->applyCreditNote($creditNote, $invoice, $this->user->id);

    // A subledger entry must be created for the credit note application event.
    $entry = SubledgerEntry::query()
        ->where('source_type', 'ar_credit_note_application')
        ->where('event', 'apply')
        ->first();

    expect($entry)->not->toBeNull();

    // Entry must have exactly 2 lines.
    $lines = SubledgerLine::where('entry_id', $entry->id)->get();
    expect($lines)->toHaveCount(2);

    // Both lines must hit the same account.
    $accountIds = $lines->pluck('account_id')->unique();
    expect($accountIds)->toHaveCount(1);

    // One line debit, one line credit.
    $totalDebit = $lines->sum('debit');
    $totalCredit = $lines->sum('credit');
    expect($totalDebit)->toBeGreaterThan(0);
    expect($totalCredit)->toBeGreaterThan(0);

    // Entry is balanced (net zero).
    expect(abs((float) $totalDebit - (float) $totalCredit))->toBeLessThan(0.001);
});

it('credit note application subledger entry is idempotent on retry', function () {
    $customer = Customer::factory()->create();

    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-CN-002',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'total_cents' => 10000,
        'paid_total_cents' => 0,
        'balance_cents' => 10000,
    ]);

    $creditNote = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'type' => 'credit_note',
        'status' => 'issued',
        'invoice_number' => 'CN-002',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'total_cents' => -10000,
        'paid_total_cents' => 0,
        'balance_cents' => -10000,
    ]);

    /** @var ArAllocationService $svc */
    $svc = app(ArAllocationService::class);
    $svc->applyCreditNote($creditNote, $invoice, $this->user->id);

    // Retrieve the voucher payment created by the first call so we can call
    // recordArCreditNoteApplied directly a second time with the same IDs,
    // exercising the catch-block idempotency path in SubledgerService::recordEntry().
    $entry = SubledgerEntry::query()
        ->where('source_type', 'ar_credit_note_application')
        ->where('event', 'apply')
        ->firstOrFail();

    /** @var \App\Services\Ledger\SubledgerService $subledger */
    $subledger = app(\App\Services\Ledger\SubledgerService::class);

    // Fetch the voucher payment anchored to the existing subledger entry.
    $voucherPayment = Payment::find($entry->source_id);

    if ($voucherPayment) {
        // A direct second call must return the existing entry without creating a duplicate.
        $second = $subledger->recordArCreditNoteApplied(
            $voucherPayment,
            $creditNote->fresh(),
            $invoice->fresh(),
            10000,
            $this->user->id
        );

        expect($second)->not->toBeNull();
        expect((int) $second->id)->toBe((int) $entry->id);
    }

    $count = SubledgerEntry::query()
        ->where('source_type', 'ar_credit_note_application')
        ->where('event', 'apply')
        ->count();

    expect($count)->toBe(1);
});

<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\SubledgerEntry;
use App\Models\User;
use App\Services\AR\ArInvoiceService;
use App\Services\Ledger\SubledgerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->user = User::factory()->create(['status' => 'active']);
    $this->user->assignRole('admin');
});

it('prevents duplicate subledger entries when recordArInvoiceIssued is called twice for the same invoice', function () {
    $customer = Customer::factory()->create();

    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-IDEM-001',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'total_cents' => 10000,
        'paid_total_cents' => 0,
        'balance_cents' => 10000,
    ]);

    /** @var SubledgerService $svc */
    $svc = app(SubledgerService::class);

    // First call creates the entry.
    $first = $svc->recordArInvoiceIssued($invoice->fresh(), $this->user->id);

    // Second call must return the existing entry (not throw, not create a duplicate).
    $second = $svc->recordArInvoiceIssued($invoice->fresh(), $this->user->id);

    $count = SubledgerEntry::query()
        ->where('source_type', 'ar_invoice')
        ->where('source_id', $invoice->id)
        ->where('event', 'issue')
        ->count();

    expect($count)->toBe(1);
    expect($second)->not->toBeNull();
    expect((int) $second->id)->toBe((int) $first->id);
});

it('subledger_entries table enforces unique (source_type, source_id, event) at DB level', function () {
    if (! DB::getSchemaBuilder()->hasTable('subledger_entries')) {
        $this->markTestSkipped('subledger_entries table does not exist.');
    }

    // Insert a seed row via the service so we have a valid entry to duplicate.
    $customer = Customer::factory()->create();
    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-IDEM-002',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'total_cents' => 5000,
        'paid_total_cents' => 0,
        'balance_cents' => 5000,
    ]);

    /** @var SubledgerService $svc */
    $svc = app(SubledgerService::class);
    $entry = $svc->recordArInvoiceIssued($invoice->fresh(), $this->user->id);

    if (! $entry) {
        $this->markTestSkipped('SubledgerService did not create an entry (subledger not fully configured).');
    }

    // A raw duplicate insert must throw a QueryException from the DB unique constraint.
    $this->expectException(QueryException::class);

    DB::table('subledger_entries')->insert([
        'source_type' => $entry->source_type,
        'source_id' => $entry->source_id,
        'company_id' => $entry->company_id,
        'event' => $entry->event,
        'entry_date' => $entry->entry_date,
        'status' => 'posted',
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

<?php

use App\Models\AccountingAccountMapping;
use App\Models\AccountingCompany;
use App\Models\ApInvoice;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\SubledgerEntry;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AR\ArInvoiceService;
use App\Services\AR\ArPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->user = User::factory()->create(['status' => 'active']);
    $this->user->assignRole('admin');
});

it('posts supplier expenses to the supplier default expense account', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $expenseAccount = LedgerAccount::query()->create([
        'company_id' => $company->id,
        'code' => '6110',
        'name' => 'Marketing Expense',
        'type' => 'expense',
        'account_class' => 'expense',
        'allow_direct_posting' => true,
        'is_active' => true,
    ]);

    $supplier = Supplier::factory()->create([
        'company_id' => $company->id,
        'default_expense_account_id' => $expenseAccount->id,
    ]);
    $category = ExpenseCategory::factory()->create();

    $create = $this->actingAs($this->user)->postJson('/api/ap/invoices', [
        'supplier_id' => $supplier->id,
        'document_type' => 'expense',
        'category_id' => $category->id,
        'invoice_number' => 'EXP-100',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'tax_amount' => 0,
        'items' => [
            ['description' => 'Campaign', 'quantity' => 1, 'unit_price' => 100],
        ],
    ])->assertCreated();

    $invoiceId = (int) $create->json('id');

    $this->actingAs($this->user)->postJson("/api/ap/invoices/{$invoiceId}/post")->assertOk();

    $entry = SubledgerEntry::query()
        ->where('source_type', 'ap_invoice')
        ->where('source_id', $invoiceId)
        ->where('event', 'post')
        ->firstOrFail();

    expect($entry->lines()->where('debit', 100)->value('account_id'))->toBe($expenseAccount->id);
});

it('posts AP card payments to the mapped card clearing account without creating a bank transaction', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $cardAccount = LedgerAccount::query()->where('code', '1020')->firstOrFail();
    AccountingAccountMapping::query()->updateOrCreate(
        ['company_id' => $company->id, 'mapping_key' => 'card_clearing'],
        ['ledger_account_id' => $cardAccount->id]
    );

    $supplier = Supplier::factory()->create(['company_id' => $company->id]);

    $create = $this->actingAs($this->user)->postJson('/api/ap/invoices', [
        'supplier_id' => $supplier->id,
        'document_type' => 'vendor_bill',
        'invoice_number' => 'BILL-200',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'tax_amount' => 0,
        'items' => [
            ['description' => 'Stock', 'quantity' => 1, 'unit_price' => 150],
        ],
    ])->assertCreated();

    $invoiceId = (int) $create->json('id');
    $this->actingAs($this->user)->postJson("/api/ap/invoices/{$invoiceId}/post")->assertOk();

    $this->actingAs($this->user)->postJson('/api/ap/payments', [
        'supplier_id' => $supplier->id,
        'company_id' => $company->id,
        'payment_date' => now()->toDateString(),
        'amount' => 150,
        'payment_method' => 'card',
        'allocations' => [
            ['invoice_id' => $invoiceId, 'allocated_amount' => 150],
        ],
    ])->assertCreated();

    $payment = ApInvoice::query()->findOrFail($invoiceId)->allocations()->firstOrFail()->payment;
    $entry = SubledgerEntry::query()
        ->where('source_type', 'ap_payment')
        ->where('source_id', $payment->id)
        ->where('event', 'payment')
        ->firstOrFail();

    expect($entry->lines()->where('credit', 150)->value('account_id'))->toBe($cardAccount->id);
    expect(\App\Models\BankTransaction::query()->where('source_id', $payment->id)->exists())->toBeFalse();
});

it('posts AR bank transfers to the selected bank account and creates a bank transaction', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $bankAccount = BankAccount::query()->where('company_id', $company->id)->where('is_default', true)->firstOrFail();
    $customer = Customer::factory()->create();

    /** @var ArInvoiceService $invoiceService */
    $invoiceService = app(ArInvoiceService::class);
    $invoice = $invoiceService->issue($invoiceService->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Consulting', 'qty' => '1.000', 'unit_price_cents' => 25000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 25000],
        ],
        actorId: $this->user->id,
    ), $this->user->id);

    /** @var ArPaymentService $payments */
    $payments = app(ArPaymentService::class);
    $payment = $payments->createPaymentWithAllocations([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'amount_cents' => 25000,
        'method' => 'bank_transfer',
        'bank_account_id' => $bankAccount->id,
        'received_at' => now()->toDateTimeString(),
        'allocations' => [
            ['invoice_id' => $invoice->id, 'amount_cents' => 25000],
        ],
    ], $this->user->id);

    $entry = SubledgerEntry::query()
        ->where('source_type', 'ar_payment')
        ->where('source_id', $payment->id)
        ->where('event', 'payment')
        ->firstOrFail();

    expect((int) $payment->bank_account_id)->toBe((int) $bankAccount->id);
    expect($entry->lines()->where('debit', 250)->value('account_id'))->toBe((int) $bankAccount->ledger_account_id);
    expect(\App\Models\BankTransaction::query()
        ->where('source_type', Payment::class)
        ->where('source_id', $payment->id)
        ->where('bank_account_id', $bankAccount->id)
        ->exists())->toBeTrue();
});

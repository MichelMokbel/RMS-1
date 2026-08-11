<?php

use App\Models\AccountingAuditLog;
use App\Models\AccountingCompany;
use App\Models\AccountingPeriod;
use App\Models\ApInvoice;
use App\Models\ApInvoiceAttachment;
use App\Models\ApInvoiceItem;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Models\ExpenseProfile;
use App\Models\Job;
use App\Models\JobTransaction;
use App\Models\LedgerAccount;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderInvoiceMatch;
use App\Models\PurchaseOrderItem;
use App\Models\SubledgerEntry;
use App\Models\SubledgerLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\AP\ApInvoiceAttachmentService;
use App\Services\AP\ApInvoiceVoidService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake('s3');
    Role::findOrCreate('admin');
    Role::findOrCreate('manager');
    $this->user = User::factory()->create(['status' => 'active']);
    $this->user->assignRole('admin');
});

function revisionTestCompanyAndPeriod(): array
{
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $period = AccountingPeriod::query()
        ->where('company_id', $company->id)
        ->whereDate('start_date', '<=', now()->toDateString())
        ->whereDate('end_date', '>=', now()->toDateString())
        ->firstOrFail();

    return [$company, $period];
}

function revisionTestPostedInvoice(array $overrides = []): ApInvoice
{
    [$company, $period] = revisionTestCompanyAndPeriod();
    $supplier = $overrides['supplier'] ?? Supplier::factory()->create(['company_id' => $company->id]);
    unset($overrides['supplier']);

    $invoice = ApInvoice::factory()->create(array_merge([
        'company_id' => $company->id,
        'period_id' => $period->id,
        'supplier_id' => $supplier->id,
        'invoice_number' => 'AP-REV-'.fake()->unique()->numerify('#####'),
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'subtotal' => 150,
        'tax_amount' => 0,
        'total_amount' => 150,
        'status' => 'posted',
        'posted_at' => now(),
        'document_type' => 'vendor_bill',
    ], $overrides));

    ApInvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Revision test line',
        'quantity' => 1,
        'unit_price' => 150,
        'line_total' => 150,
    ]);

    revisionTestCreatePostEntry($invoice);

    return $invoice->fresh(['items']);
}

function revisionTestCreatePostEntry(ApInvoice $invoice): SubledgerEntry
{
    $accounts = LedgerAccount::query()
        ->where('company_id', $invoice->company_id)
        ->orderBy('id')
        ->limit(2)
        ->get();

    expect($accounts)->toHaveCount(2);

    $entry = SubledgerEntry::query()->create([
        'source_type' => 'ap_invoice',
        'source_id' => $invoice->id,
        'company_id' => $invoice->company_id,
        'event' => 'post',
        'entry_date' => now()->toDateString(),
        'description' => 'AP revision test post',
        'source_document_type' => 'ap_invoice',
        'source_document_id' => $invoice->id,
        'period_id' => $invoice->period_id,
        'currency_code' => 'QAR',
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    SubledgerLine::query()->create([
        'entry_id' => $entry->id,
        'account_id' => $accounts[0]->id,
        'debit' => 150,
        'credit' => 0,
        'memo' => 'Original debit',
    ]);
    SubledgerLine::query()->create([
        'entry_id' => $entry->id,
        'account_id' => $accounts[1]->id,
        'debit' => 0,
        'credit' => 150,
        'memo' => 'Original credit',
    ]);

    return $entry->fresh('lines');
}

it('creates V1 and V2 drafts with normalized revision lineage and audit records', function () {
    $service = app(ApInvoiceVoidService::class);
    $original = revisionTestPostedInvoice(['invoice_number' => 'SUP-BILL-100']);

    $revision1 = $service->voidAndDuplicate($original, $this->user->id, 'Correct quantity');

    expect($original->fresh()->status)->toBe('void')
        ->and($original->fresh()->void_reason)->toBe('Correct quantity')
        ->and($revision1->status)->toBe('draft')
        ->and($revision1->invoice_number)->toBe('SUP-BILL-100V1')
        ->and($revision1->revision_root_id)->toBe($original->id)
        ->and($revision1->revision_source_id)->toBe($original->id)
        ->and($revision1->revision_number)->toBe(1)
        ->and($revision1->period_id)->toBeNull()
        ->and($revision1->items)->toHaveCount(1);

    $revision1->status = 'posted';
    $revision1->posted_at = now();
    $revision1->posted_by = $this->user->id;
    $revision1->save();
    revisionTestCreatePostEntry($revision1->fresh());

    $revision2 = $service->voidAndDuplicate($revision1->fresh(), $this->user->id, 'Correct tax');

    expect($revision2->invoice_number)->toBe('SUP-BILL-100V2')
        ->and($revision2->revision_root_id)->toBe($original->id)
        ->and($revision2->revision_source_id)->toBe($revision1->id)
        ->and($revision2->revision_number)->toBe(2);

    expect(AccountingAuditLog::query()
        ->where('action', 'ap_invoice.voided_and_duplicated')
        ->where('subject_id', $revision1->id)
        ->where('payload->revision_invoice_id', $revision2->id)
        ->exists())->toBeTrue();
});

it('copies attachments into independent revision objects and preserves the originals', function () {
    $invoice = revisionTestPostedInvoice(['invoice_number' => 'AP-ATTACH-100']);
    $sourcePaths = [
        'ap-invoices/'.$invoice->id.'/source-image.jpg' => 'image evidence',
        'ap-invoices/'.$invoice->id.'/source-document.pdf' => 'pdf evidence',
    ];

    foreach ($sourcePaths as $path => $contents) {
        Storage::disk('s3')->put($path, $contents);
        ApInvoiceAttachment::query()->create([
            'invoice_id' => $invoice->id,
            'file_path' => $path,
            'original_name' => basename($path),
            'uploaded_by' => $this->user->id,
        ]);
    }

    $revision = app(ApInvoiceVoidService::class)
        ->voidAndDuplicate($invoice->fresh(), $this->user->id, 'Attachment correction');
    $sourceAttachments = $invoice->attachments()->orderBy('id')->get();
    $revisionAttachments = $revision->attachments()->orderBy('id')->get();

    expect($revisionAttachments)->toHaveCount(2)
        ->and($revisionAttachments->pluck('original_name')->all())
        ->toBe($sourceAttachments->pluck('original_name')->all());

    foreach ($revisionAttachments as $index => $revisionAttachment) {
        $sourceAttachment = $sourceAttachments[$index];

        expect($revisionAttachment->file_path)
            ->toStartWith('ap-invoices/'.$revision->id.'/')
            ->not->toBe($sourceAttachment->file_path)
            ->and($revisionAttachment->uploaded_by)->toBe($sourceAttachment->uploaded_by)
            ->and(Storage::disk('s3')->get($revisionAttachment->file_path))
            ->toBe(Storage::disk('s3')->get($sourceAttachment->file_path));
    }

    $deletedRevisionAttachment = $revisionAttachments->first();
    $deletedRevisionPath = $deletedRevisionAttachment->file_path;
    app(ApInvoiceAttachmentService::class)->delete($deletedRevisionAttachment, $this->user->id);

    expect(Storage::disk('s3')->exists($deletedRevisionPath))->toBeFalse()
        ->and(Storage::disk('s3')->exists($sourceAttachments->first()->file_path))->toBeTrue()
        ->and(ApInvoiceAttachment::query()->whereKey($sourceAttachments->first()->id)->exists())->toBeTrue()
        ->and(AccountingAuditLog::query()
            ->where('action', 'ap_invoice.revision_attachments_copied')
            ->where('subject_id', $revision->id)
            ->where('payload->source_invoice_id', $invoice->id)
            ->exists())->toBeTrue();
});

it('rolls back the revision and accounting reversal when a source attachment is missing', function () {
    $invoice = revisionTestPostedInvoice(['invoice_number' => 'AP-ATTACH-MISSING']);
    ApInvoiceAttachment::query()->create([
        'invoice_id' => $invoice->id,
        'file_path' => 'ap-invoices/'.$invoice->id.'/missing.pdf',
        'original_name' => 'missing.pdf',
        'uploaded_by' => $this->user->id,
    ]);

    expect(fn () => app(ApInvoiceVoidService::class)
        ->voidAndDuplicate($invoice->fresh(), $this->user->id, 'Missing evidence'))
        ->toThrow(ValidationException::class, 'missing from storage');

    expect($invoice->fresh()->status)->toBe('posted')
        ->and(ApInvoice::query()->where('revision_source_id', $invoice->id)->exists())->toBeFalse()
        ->and(SubledgerEntry::query()
            ->where('source_type', 'ap_invoice')
            ->where('source_id', $invoice->id)
            ->where('event', 'void')
            ->exists())->toBeFalse()
        ->and($invoice->attachments()->count())->toBe(1)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

it('copies the immediate source version attachment set into the next version', function () {
    $invoice = revisionTestPostedInvoice(['invoice_number' => 'AP-ATTACH-CHAIN']);
    $rootPath = 'ap-invoices/'.$invoice->id.'/root.pdf';
    Storage::disk('s3')->put($rootPath, 'root evidence');
    ApInvoiceAttachment::query()->create([
        'invoice_id' => $invoice->id,
        'file_path' => $rootPath,
        'original_name' => 'root.pdf',
        'uploaded_by' => $this->user->id,
    ]);

    $revision1 = app(ApInvoiceVoidService::class)
        ->voidAndDuplicate($invoice->fresh(), $this->user->id, 'First correction');
    app(ApInvoiceAttachmentService::class)
        ->delete($revision1->attachments()->firstOrFail(), $this->user->id);

    $replacementPath = 'ap-invoices/'.$revision1->id.'/replacement.pdf';
    Storage::disk('s3')->put($replacementPath, 'replacement evidence');
    ApInvoiceAttachment::query()->create([
        'invoice_id' => $revision1->id,
        'file_path' => $replacementPath,
        'original_name' => 'replacement.pdf',
        'uploaded_by' => $this->user->id,
    ]);
    $revision1->forceFill([
        'status' => 'posted',
        'posted_at' => now(),
        'posted_by' => $this->user->id,
    ])->save();
    revisionTestCreatePostEntry($revision1->fresh());

    $revision2 = app(ApInvoiceVoidService::class)
        ->voidAndDuplicate($revision1->fresh(), $this->user->id, 'Second correction');
    $revision2Attachments = $revision2->attachments()->get();

    expect($revision2Attachments)->toHaveCount(1)
        ->and($revision2Attachments->first()->original_name)->toBe('replacement.pdf')
        ->and(Storage::disk('s3')->get($revision2Attachments->first()->file_path))
        ->toBe('replacement evidence')
        ->and(Storage::disk('s3')->exists($rootPath))->toBeTrue();
});

it('removes copied objects when the revision transaction fails after copying', function () {
    $invoice = revisionTestPostedInvoice(['invoice_number' => 'AP-ATTACH-CLEANUP']);
    $sourcePath = 'ap-invoices/'.$invoice->id.'/source.pdf';
    Storage::disk('s3')->put($sourcePath, 'source evidence');
    ApInvoiceAttachment::query()->create([
        'invoice_id' => $invoice->id,
        'file_path' => $sourcePath,
        'original_name' => 'source.pdf',
        'uploaded_by' => $this->user->id,
    ]);

    app()->instance(AccountingAuditLogService::class, new class extends AccountingAuditLogService
    {
        public function log(
            string $action,
            ?int $actorId = null,
            Model|string|null $subject = null,
            array $payload = [],
            ?int $companyId = null
        ): void {
            if ($action === 'ap_invoice.revision_created') {
                throw new RuntimeException('Forced post-copy failure');
            }
        }
    });

    expect(fn () => app(ApInvoiceVoidService::class)
        ->voidAndDuplicate($invoice->fresh(), $this->user->id, 'Cleanup check'))
        ->toThrow(RuntimeException::class, 'Forced post-copy failure');

    expect($invoice->fresh()->status)->toBe('posted')
        ->and(ApInvoice::query()->where('revision_source_id', $invoice->id)->exists())->toBeFalse()
        ->and(SubledgerEntry::query()
            ->where('source_type', 'ap_invoice')
            ->where('source_id', $invoice->id)
            ->where('event', 'void')
            ->exists())->toBeFalse()
        ->and(Storage::disk('s3')->allFiles())->toBe([$sourcePath]);
});

it('reverses the exact original AP posting and keeps the reversal balanced', function () {
    $invoice = revisionTestPostedInvoice();
    $post = SubledgerEntry::query()
        ->where('source_type', 'ap_invoice')
        ->where('source_id', $invoice->id)
        ->where('event', 'post')
        ->firstOrFail()
        ->load('lines');

    app(ApInvoiceVoidService::class)->void($invoice, $this->user->id, 'Correction');

    $void = SubledgerEntry::query()
        ->where('source_type', 'ap_invoice')
        ->where('source_id', $invoice->id)
        ->where('event', 'void')
        ->firstOrFail()
        ->load('lines');

    foreach ($post->lines as $originalLine) {
        $reversalLine = $void->lines->firstWhere('account_id', $originalLine->account_id);
        expect($reversalLine)->not->toBeNull()
            ->and((float) $reversalLine->debit)->toBe((float) $originalLine->credit)
            ->and((float) $reversalLine->credit)->toBe((float) $originalLine->debit);
    }

    expect(round((float) $void->lines->sum('debit'), 4))
        ->toBe(round((float) $void->lines->sum('credit'), 4));
});

it('rejects AP revision when the invoice has an active allocation', function () {
    $invoice = revisionTestPostedInvoice();
    $payment = ApPayment::query()->create([
        'supplier_id' => $invoice->supplier_id,
        'company_id' => $invoice->company_id,
        'payment_date' => now()->toDateString(),
        'amount' => 25,
        'payment_method' => 'cash',
        'currency_code' => 'QAR',
        'posted_at' => now(),
        'posted_by' => $this->user->id,
    ]);
    ApPaymentAllocation::query()->create([
        'payment_id' => $payment->id,
        'invoice_id' => $invoice->id,
        'allocated_amount' => 25,
    ]);

    expect(fn () => app(ApInvoiceVoidService::class)->voidAndDuplicate($invoice, $this->user->id))
        ->toThrow(ValidationException::class, 'Cannot void an invoice with allocations.');

    expect($invoice->fresh()->status)->toBe('posted');
});

it('rejects landed cost revisions before changing invoice or inventory state', function () {
    $invoice = revisionTestPostedInvoice(['document_type' => 'landed_cost_adjustment']);

    expect(fn () => app(ApInvoiceVoidService::class)->voidAndDuplicate($invoice, $this->user->id))
        ->toThrow(ValidationException::class, 'Landed cost adjustments cannot be revised');

    expect($invoice->fresh()->status)->toBe('posted');
});

it('rejects correction when the current accounting period is closed', function () {
    $invoice = revisionTestPostedInvoice();
    AccountingPeriod::query()->whereKey($invoice->period_id)->update(['status' => 'closed']);

    expect(fn () => app(ApInvoiceVoidService::class)->voidAndDuplicate($invoice, $this->user->id))
        ->toThrow(ValidationException::class, 'closed');

    expect($invoice->fresh()->status)->toBe('posted')
        ->and(SubledgerEntry::query()
            ->where('source_type', 'ap_invoice')
            ->where('source_id', $invoice->id)
            ->where('event', 'void')
            ->exists())->toBeFalse();
});

it('releases PO matches and records their audit evidence during revision', function () {
    [$company] = revisionTestCompanyAndPeriod();
    $supplier = Supplier::factory()->create(['company_id' => $company->id]);
    $po = PurchaseOrder::factory()->create([
        'company_id' => $company->id,
        'supplier_id' => $supplier->id,
    ]);
    $poItem = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $po->id,
        'quantity' => 1,
        'unit_price' => 150,
        'total_price' => 150,
    ]);
    $invoice = revisionTestPostedInvoice([
        'supplier' => $supplier,
        'purchase_order_id' => $po->id,
    ]);
    $invoiceItem = $invoice->items()->firstOrFail();
    $invoiceItem->purchase_order_item_id = $poItem->id;
    $invoiceItem->saveQuietly();

    $match = PurchaseOrderInvoiceMatch::query()->create([
        'company_id' => $company->id,
        'purchase_order_id' => $po->id,
        'purchase_order_item_id' => $poItem->id,
        'ap_invoice_id' => $invoice->id,
        'ap_invoice_item_id' => $invoiceItem->id,
        'matched_quantity' => 1,
        'matched_amount' => 150,
        'received_value' => 150,
        'invoiced_value' => 150,
        'price_variance' => 0,
        'status' => 'matched',
    ]);

    app(ApInvoiceVoidService::class)->voidAndDuplicate($invoice, $this->user->id, 'PO correction');

    expect(PurchaseOrderInvoiceMatch::query()->whereKey($match->id)->exists())->toBeFalse()
        ->and(AccountingAuditLog::query()
            ->where('action', 'ap_invoice.po_matches_released')
            ->where('subject_id', $invoice->id)
            ->exists())->toBeTrue();
});

it('creates one idempotent job-cost offset for a revised AP invoice', function () {
    [$company] = revisionTestCompanyAndPeriod();
    $job = Job::query()->create([
        'company_id' => $company->id,
        'name' => 'Revision Job',
        'code' => 'REV-JOB-'.fake()->unique()->numerify('###'),
        'status' => 'active',
    ]);
    $invoice = revisionTestPostedInvoice(['job_id' => $job->id]);
    $original = JobTransaction::query()->create([
        'job_id' => $job->id,
        'company_id' => $company->id,
        'transaction_date' => now()->toDateString(),
        'amount' => 150,
        'transaction_type' => 'cost',
        'source_type' => ApInvoice::class,
        'source_id' => $invoice->id,
        'memo' => 'AP invoice cost',
    ]);

    app(ApInvoiceVoidService::class)->voidAndDuplicate($invoice, $this->user->id, 'Job correction');
    app(\App\Services\Accounting\JobCostingService::class)->reverseSourceTransactions(
        ApInvoice::class,
        $invoice->id,
        $this->user->id,
        now()->toDateString(),
    );

    $offsets = JobTransaction::query()
        ->where('source_type', ApInvoice::class.'#void')
        ->where('source_id', $original->id)
        ->get();

    expect($offsets)->toHaveCount(1)
        ->and((float) $offsets->first()->amount)->toBe(-150.0)
        ->and((float) $job->transactions()->sum('amount'))->toBe(0.0);
});

it('clones expense classification into a fresh unapproved profile', function () {
    $invoice = revisionTestPostedInvoice([
        'document_type' => 'expense',
        'is_expense' => true,
    ]);
    ExpenseProfile::query()->create([
        'invoice_id' => $invoice->id,
        'channel' => 'vendor',
        'approval_status' => 'approved',
        'requires_finance_approval' => true,
        'submitted_by' => $this->user->id,
        'submitted_at' => now(),
        'manager_approved_by' => $this->user->id,
        'manager_approved_at' => now(),
    ]);

    $revision = app(ApInvoiceVoidService::class)->voidAndDuplicate($invoice->fresh(), $this->user->id, 'Expense correction');
    $profile = $revision->expenseProfile;

    expect($profile)->not->toBeNull()
        ->and($profile->channel)->toBe('vendor')
        ->and($profile->approval_status)->toBe('draft')
        ->and($profile->requires_finance_approval)->toBeFalse()
        ->and($profile->submitted_by)->toBeNull()
        ->and($profile->manager_approved_by)->toBeNull()
        ->and($profile->settled_at)->toBeNull();
});

it('exposes an admin-only API endpoint that returns the new revision draft', function () {
    $invoice = revisionTestPostedInvoice(['invoice_number' => 'API-REV-100']);

    $this->actingAs($this->user)
        ->postJson(route('api.ap.invoices.revise', $invoice), ['reason' => 'API correction'])
        ->assertCreated()
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('invoice_number', 'API-REV-100V1')
        ->assertJsonPath('revision_source_id', $invoice->id);
});

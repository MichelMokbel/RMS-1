<?php

namespace App\Services\PettyCash;

use App\Enums\PettyCash\PettyCashImportStatus;
use App\Models\ExpenseCategory;
use App\Models\PettyCashImportBatch;
use App\Models\PettyCashImportCategoryProposal;
use App\Models\PettyCashImportEditEvent;
use App\Models\PettyCashImportInvoice;
use App\Models\PettyCashImportRow;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\Accounting\LedgerAccountMappingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PettyCashImportEditor
{
    public function __construct(
        protected PettyCashImportValidator $validator,
        protected AccountingContextService $accountingContext,
        protected AccountingPeriodGateService $periodGate,
        protected LedgerAccountMappingService $mappings,
    ) {}

    public function updateInvoice(
        PettyCashImportInvoice $invoice,
        int $expectedRevision,
        array $data,
        User $actor,
    ): PettyCashImportBatch {
        return $this->mutate($invoice->batch, $expectedRevision, $actor, function (PettyCashImportBatch $batch) use ($invoice, $data, $actor): void {
            $locked = PettyCashImportInvoice::query()->where('import_batch_id', $batch->id)->lockForUpdate()->findOrFail($invoice->id);
            $before = ['business_date' => $locked->business_date?->format('Y-m-d'), 'header' => $locked->header];
            foreach ($locked->rows()->lockForUpdate()->get() as $row) {
                $row->forceFill(['payload' => $this->applyInvoiceChanges($row->payload, $data)])->save();
            }
            if (array_key_exists('business_date', $data)) {
                $locked->forceFill(['business_date' => $data['business_date']])->save();
            }
            $this->event($batch, 'invoice.updated', $actor, $before, $data, $locked);
        });
    }

    public function addInvoice(
        PettyCashImportBatch $batch,
        int $expectedRevision,
        array $data,
        User $actor,
    ): PettyCashImportBatch {
        return $this->mutate($batch, $expectedRevision, $actor, function (PettyCashImportBatch $locked) use ($data, $actor): void {
            $entryId = Str::upper(trim((string) ($data['entry_id'] ?? '')));
            $date = trim((string) ($data['business_date'] ?? ''));
            if ($entryId === '') {
                throw ValidationException::withMessages(['entry_id' => __('Entry ID is required.')]);
            }
            $invoice = PettyCashImportInvoice::query()->create([
                'import_batch_id' => $locked->id,
                'entry_id' => $entryId,
                'business_date' => $date ?: $locked->business_date?->format('Y-m-d'),
                'group_key' => hash('sha256', $date.'|'.$entryId),
                'status' => 'invalid',
                'header' => [],
                'original_header' => null,
                'errors' => ['rows' => __('This added expense must be validated.')],
                'client_uuid' => (string) Str::uuid(),
            ]);
            $payload = $this->applyInvoiceChanges(['entry_id' => $entryId], $data);
            $payload = array_merge($payload, (array) ($data['row'] ?? []));
            $this->createRow($locked, $invoice, $payload, null);
            $this->event($locked, 'invoice.added', $actor, null, $data, $invoice);
        });
    }

    public function toggleInvoiceExcluded(
        PettyCashImportInvoice $invoice,
        int $expectedRevision,
        bool $excluded,
        User $actor,
    ): PettyCashImportBatch {
        return $this->mutate($invoice->batch, $expectedRevision, $actor, function (PettyCashImportBatch $batch) use ($invoice, $excluded, $actor): void {
            $locked = PettyCashImportInvoice::query()->where('import_batch_id', $batch->id)->lockForUpdate()->findOrFail($invoice->id);
            $before = ['excluded' => (bool) $locked->excluded];
            $locked->forceFill(['excluded' => $excluded])->save();
            $this->event($batch, $excluded ? 'invoice.excluded' : 'invoice.restored', $actor, $before, ['excluded' => $excluded], $locked);
        });
    }

    public function updateRow(
        PettyCashImportRow $row,
        int $expectedRevision,
        array $data,
        User $actor,
    ): PettyCashImportBatch {
        return $this->mutate($row->batch, $expectedRevision, $actor, function (PettyCashImportBatch $batch) use ($row, $data, $actor): void {
            $locked = PettyCashImportRow::query()->where('import_batch_id', $batch->id)->lockForUpdate()->findOrFail($row->id);
            $before = $locked->payload;
            $locked->forceFill(['payload' => array_merge($locked->payload, collect($data)->only([
                'description', 'quantity', 'unit_price',
            ])->all())])->save();
            $this->event($batch, 'row.updated', $actor, $before, $locked->payload, $locked->importInvoice, $locked);
        });
    }

    public function addRow(
        PettyCashImportInvoice $invoice,
        int $expectedRevision,
        array $data,
        User $actor,
    ): PettyCashImportBatch {
        return $this->mutate($invoice->batch, $expectedRevision, $actor, function (PettyCashImportBatch $batch) use ($invoice, $data, $actor): void {
            $locked = PettyCashImportInvoice::query()->where('import_batch_id', $batch->id)->lockForUpdate()->findOrFail($invoice->id);
            $first = $locked->rows()->orderBy('row_number')->firstOrFail()->payload;
            $payload = array_merge($first, collect($data)->only(['description', 'quantity', 'unit_price'])->all());
            $row = $this->createRow($batch, $locked, $payload, null);
            $this->event($batch, 'row.added', $actor, null, $payload, $locked, $row);
        });
    }

    public function toggleRowExcluded(
        PettyCashImportRow $row,
        int $expectedRevision,
        bool $excluded,
        User $actor,
    ): PettyCashImportBatch {
        return $this->mutate($row->batch, $expectedRevision, $actor, function (PettyCashImportBatch $batch) use ($row, $excluded, $actor): void {
            $locked = PettyCashImportRow::query()->where('import_batch_id', $batch->id)->lockForUpdate()->findOrFail($row->id);
            $before = ['excluded' => (bool) $locked->excluded];
            $locked->forceFill(['excluded' => $excluded])->save();
            $this->event($batch, $excluded ? 'row.excluded' : 'row.restored', $actor, $before, ['excluded' => $excluded], $locked->importInvoice, $locked);
        });
    }

    public function bulkOverride(
        PettyCashImportBatch $batch,
        int $expectedRevision,
        array $filters,
        array $values,
        User $actor,
    ): PettyCashImportBatch {
        return $this->mutate($batch, $expectedRevision, $actor, function (PettyCashImportBatch $locked) use ($filters, $values, $actor): void {
            $query = PettyCashImportInvoice::query()->where('import_batch_id', $locked->id)->where('excluded', false);
            foreach (['business_date', 'status'] as $field) {
                if (filled($filters[$field] ?? null)) {
                    $query->where($field, $filters[$field]);
                }
            }
            $invoices = $query->lockForUpdate()->get()->filter(function (PettyCashImportInvoice $invoice) use ($filters): bool {
                $header = $invoice->header;
                foreach (['supplier_id', 'category_id', 'wallet_id', 'paid'] as $field) {
                    $effective = $field === 'paid'
                        ? ($header['paid_requested'] ?? $header['paid'] ?? null)
                        : ($header[$field] ?? null);
                    if (array_key_exists($field, $filters)
                        && $filters[$field] !== ''
                        && $filters[$field] !== null
                        && $effective != $filters[$field]) {
                        return false;
                    }
                }
                if (filled($filters['category'] ?? null)
                    && ! str_contains(mb_strtolower((string) ($header['category_name'] ?? '')), mb_strtolower(trim($filters['category'])))) {
                    return false;
                }

                return true;
            });
            foreach ($invoices as $invoice) {
                foreach ($invoice->rows()->lockForUpdate()->get() as $row) {
                    $row->forceFill(['payload' => $this->applyInvoiceChanges($row->payload, $values)])->save();
                }
            }
            $this->event($locked, 'invoices.bulk_overridden', $actor, ['filters' => $filters], [
                'values' => $values,
                'invoice_ids' => $invoices->pluck('id')->all(),
            ]);
        });
    }

    public function revalidate(
        PettyCashImportBatch $batch,
        int $expectedRevision,
        User $actor,
    ): PettyCashImportBatch {
        return $this->mutate($batch, $expectedRevision, $actor, function (PettyCashImportBatch $locked) use ($actor): void {
            $this->event($locked, 'batch.revalidated', $actor, null, ['revision' => $locked->revision]);
        });
    }

    private function mutate(PettyCashImportBatch $batch, int $revision, User $actor, callable $callback): PettyCashImportBatch
    {
        $this->assertAccess($actor);

        return DB::transaction(function () use ($batch, $revision, $callback): PettyCashImportBatch {
            $locked = PettyCashImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->assertMutable($locked, $revision);
            $callback($locked);
            $this->revalidateLocked($locked);
            $locked->increment('revision');

            return $locked->fresh($this->relations());
        });
    }

    private function revalidateLocked(PettyCashImportBatch $batch): void
    {
        $rows = PettyCashImportRow::query()
            ->where('import_batch_id', $batch->id)
            ->where('excluded', false)
            ->whereHas('importInvoice', fn ($query) => $query->where('excluded', false))
            ->orderBy('row_number')
            ->get();
        $sourceRows = [];
        foreach ($rows as $row) {
            $sourceRows[max(0, $row->row_number - 2)] = $this->sourceRow($row->payload);
        }
        $validated = $this->validator->validate(
            $sourceRows,
            (int) $batch->company_id,
            $batch->default_category_id,
            $batch->default_wallet_id,
            $batch->business_date?->format('Y-m-d') ?? '',
            $batch->default_supplier_id,
            $batch->default_paid,
            $batch->import_mode,
        );
        $this->applyPeriodErrors($validated, $batch);
        $this->applyMappingErrors($validated, $batch);

        $invoiceResults = collect($validated['invoices'])->keyBy(
            fn (array $invoice): string => $invoice['business_date'].'|'.$invoice['entry_id']
        );
        foreach ($batch->invoices()->lockForUpdate()->get() as $invoice) {
            if ($invoice->excluded) {
                continue;
            }
            $result = $invoiceResults->get($invoice->business_date?->format('Y-m-d').'|'.$invoice->entry_id);
            if (! $result) {
                $invoice->forceFill(['status' => 'invalid', 'errors' => ['rows' => __('At least one active line is required.')]])->save();

                continue;
            }
            $invoice->forceFill([
                'business_date' => $result['business_date'],
                'group_key' => $result['group_key'],
                'header' => $result['header'],
                'errors' => $result['errors'],
                'status' => $result['status'],
            ])->save();
        }
        foreach ($validated['rows'] as $result) {
            $row = PettyCashImportRow::query()
                ->where('import_batch_id', $batch->id)
                ->where('row_number', $result['row_number'])
                ->lockForUpdate()
                ->first();
            $row?->forceFill([
                'payload' => $result['payload'],
                'errors' => $result['errors'],
                'status' => $result['status'],
                'row_hash' => hash('sha256', json_encode($result['payload'], JSON_THROW_ON_ERROR)),
            ])->save();
        }
        $this->syncInvoiceCategoryProposals($batch, $validated);
        $this->refreshProposalMatches($batch);
        $invalid = $batch->invoices()->where('excluded', false)->where('status', 'invalid')->count();
        $invalidCategories = $batch->categoryProposals()->whereIn('status', ['inactive', 'ambiguous'])->count();
        $stats = $validated['stats'];
        $stats['invalid_invoices'] = $invalid;
        $stats['valid_invoices'] = max(0, (int) $stats['invoices'] - $invalid);
        $stats['invalid_categories'] = $invalidCategories;
        $batch->forceFill([
            'status' => $invalid === 0 && $invalidCategories === 0 && (int) $stats['invoices'] > 0
                ? 'ready'
                : 'needs_review',
            'date_from' => $stats['date_from'] ?: null,
            'date_to' => $stats['date_to'] ?: null,
            'stats' => $stats,
            'failure_reason' => null,
            'failed_at' => null,
        ])->save();
    }

    private function applyPeriodErrors(array &$validated, PettyCashImportBatch $batch): void
    {
        foreach ($validated['invoices'] as &$invoice) {
            if (($invoice['errors'] ?? []) !== []) {
                continue;
            }
            try {
                $periodId = $this->accountingContext->resolvePeriodId($invoice['business_date'], (int) $batch->company_id);
                $this->periodGate->assertDateOpen($invoice['business_date'], (int) $batch->company_id, $periodId, 'ap', 'business_date');
            } catch (ValidationException $exception) {
                $invoice['errors']['business_date'] = collect($exception->errors())->flatten()->first()
                    ?? __('The accounting period is not open.');
                $invoice['status'] = 'invalid';
            }
        }
        unset($invoice);
    }

    private function applyMappingErrors(array &$validated, PettyCashImportBatch $batch): void
    {
        $invoices = collect($validated['invoices'])->filter(
            fn (array $invoice): bool => ($invoice['errors'] ?? []) === []
        );
        if ($invoices->isEmpty()) {
            return;
        }
        $required = ['ap_control'];
        if ($invoices->contains(fn (array $invoice): bool => (bool) ($invoice['header']['paid'] ?? false))) {
            $required[] = 'petty_cash_asset';
        }
        $supplierIds = $invoices->pluck('header.supplier_id')->filter()->unique();
        if (Supplier::query()->whereIn('id', $supplierIds)->whereNull('default_expense_account_id')->exists()) {
            $required[] = 'expense_default';
        }
        try {
            $this->mappings->assertRequiredMappings((int) $batch->company_id, array_unique($required));
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first()
                ?? __('Required accounting mappings are missing.');
            foreach ($validated['invoices'] as &$invoice) {
                $invoice['errors']['accounting_mapping'] = $message;
                $invoice['status'] = 'invalid';
            }
            unset($invoice);
        }
    }

    private function applyInvoiceChanges(array $payload, array $data): array
    {
        foreach (['business_date', 'reference_number', 'due_date', 'paid', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }
        foreach (['supplier', 'category', 'wallet'] as $field) {
            $idField = $field.'_id';
            if (array_key_exists($idField, $data)) {
                $payload[$field] = filled($data[$idField]) ? (string) $data[$idField] : ($data[$field] ?? null);
            } elseif (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        return $payload;
    }

    private function sourceRow(array $payload): array
    {
        return collect($payload)->only(PettyCashImportService::BULK_HEADERS)->all();
    }

    private function createRow(PettyCashImportBatch $batch, PettyCashImportInvoice $invoice, array $payload, ?array $original): PettyCashImportRow
    {
        $number = (int) PettyCashImportRow::query()->where('import_batch_id', $batch->id)->max('row_number') + 1;

        return PettyCashImportRow::query()->create([
            'import_batch_id' => $batch->id,
            'import_invoice_id' => $invoice->id,
            'row_number' => max(2, $number),
            'source_identifier' => $invoice->entry_id,
            'status' => 'invalid',
            'payload' => $payload,
            'original_payload' => $original,
            'errors' => [],
            'row_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);
    }

    private function syncInvoiceCategoryProposals(PettyCashImportBatch $batch, array $validated): void
    {
        $used = collect($validated['invoices'])->pluck('header.category_normalized')->filter()->unique();
        PettyCashImportCategoryProposal::query()
            ->where('import_batch_id', $batch->id)
            ->where('is_declared', false)
            ->whereNotIn('normalized_name', $used)
            ->delete();
        $categories = ExpenseCategory::query()->get()->keyBy(fn (ExpenseCategory $category): string => $this->normalizeName($category->name));
        foreach ($validated['invoices'] as $invoice) {
            $name = $invoice['header']['category_name'] ?? null;
            $normalized = $invoice['header']['category_normalized'] ?? null;
            if (! $name || ! $normalized) {
                continue;
            }
            $category = $categories->get($normalized);
            $proposal = PettyCashImportCategoryProposal::query()->firstOrNew([
                'import_batch_id' => $batch->id,
                'normalized_name' => $normalized,
            ]);
            $proposal->fill([
                'source_name' => $name,
                'is_declared' => $proposal->exists ? $proposal->is_declared : false,
                'status' => ! $category ? 'proposed' : ($category->active ? 'matched' : 'inactive'),
                'expense_category_id' => $category?->id,
            ])->save();
        }
    }

    private function refreshProposalMatches(PettyCashImportBatch $batch): void
    {
        $categories = ExpenseCategory::query()->get()->keyBy(
            fn (ExpenseCategory $category): string => $this->normalizeName($category->name)
        );
        foreach ($batch->categoryProposals()->lockForUpdate()->get() as $proposal) {
            $matches = $categories->get($proposal->normalized_name, collect());
            $category = $matches->count() === 1 ? $matches->first() : null;
            $proposal->forceFill([
                'status' => $matches->count() > 1
                    ? 'ambiguous'
                    : (! $category ? 'proposed' : ($category->active ? 'matched' : 'inactive')),
                'expense_category_id' => $category?->id,
            ])->save();
        }
    }

    private function event(
        PettyCashImportBatch $batch,
        string $action,
        User $actor,
        ?array $before,
        ?array $after,
        ?PettyCashImportInvoice $invoice = null,
        ?PettyCashImportRow $row = null,
    ): void {
        PettyCashImportEditEvent::query()->create([
            'import_batch_id' => $batch->id,
            'import_invoice_id' => $invoice?->id,
            'import_row_id' => $row?->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'before_values' => $before,
            'after_values' => $after,
            'created_at' => now(),
        ]);
    }

    private function assertMutable(PettyCashImportBatch $batch, int $revision): void
    {
        if ((int) $batch->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => __('This import changed in another session. Refresh and try again.')]);
        }
        if ($batch->import_mode !== 'bulk') {
            throw ValidationException::withMessages(['import_mode' => __('Only multiple-date imports can be edited on the dashboard.')]);
        }
        if (! in_array($batch->status, [PettyCashImportStatus::Ready, PettyCashImportStatus::NeedsReview], true)) {
            throw ValidationException::withMessages(['status' => __('This import can no longer be edited.')]);
        }
    }

    private function assertAccess(User $actor): void
    {
        if (! $actor->hasRole('admin') || ! $actor->can('petty_cash.import')) {
            throw new AuthorizationException(__('Only administrators may edit petty cash imports.'));
        }
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?: trim($name));
    }

    private function relations(): array
    {
        return ['invoices.rows', 'rows', 'categoryProposals', 'editEvents'];
    }
}

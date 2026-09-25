<?php

namespace App\Services\AR;

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Sequences\DocumentSequenceService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryNoteService
{
    public function __construct(
        protected DocumentSequenceService $sequences,
        protected AccountingContextService $accountingContext,
        protected AccountingAuditLogService $auditLog,
        protected ArInvoiceService $invoices,
    ) {}

    public function createDraft(array $payload, int $actorId): DeliveryNote
    {
        $branchId = (int) ($payload['branch_id'] ?? 0);
        $this->assertBranchAccess($branchId, $actorId);
        $customer = Customer::active()->find((int) ($payload['customer_id'] ?? 0));
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => __('Select an active customer.')]);
        }

        return DB::transaction(function () use ($payload, $actorId, $branchId, $customer): DeliveryNote {
            $note = DeliveryNote::create([
                'branch_id' => $branchId,
                'company_id' => $this->accountingContext->resolveCompanyId($branchId),
                'customer_id' => $customer->id,
                'status' => 'draft',
                'delivery_date' => $payload['delivery_date'] ?? now()->toDateString(),
                'customer_name_snapshot' => $customer->name,
                'delivery_address_snapshot' => trim((string) ($payload['delivery_address'] ?? $customer->delivery_address ?? '')) ?: null,
                'reference' => trim((string) ($payload['reference'] ?? '')) ?: null,
                'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $this->replaceItems($note, $payload['items'] ?? []);
            $this->auditLog->log('delivery_note.created', $actorId, $note);

            return $note->fresh(['items', 'customer']);
        });
    }

    public function updateDraft(DeliveryNote $note, array $payload, int $actorId): DeliveryNote
    {
        return DB::transaction(function () use ($note, $payload, $actorId): DeliveryNote {
            $locked = DeliveryNote::whereKey($note->id)->lockForUpdate()->firstOrFail();
            $this->assertBranchAccess((int) $locked->branch_id, $actorId);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['delivery_note' => __('Only draft delivery notes can be edited.')]);
            }
            $customer = Customer::active()->find((int) ($payload['customer_id'] ?? 0));
            if (! $customer) {
                throw ValidationException::withMessages(['customer_id' => __('Select an active customer.')]);
            }
            $locked->update([
                'customer_id' => $customer->id,
                'customer_name_snapshot' => $customer->name,
                'delivery_date' => $payload['delivery_date'],
                'delivery_address_snapshot' => trim((string) ($payload['delivery_address'] ?? '')) ?: null,
                'reference' => trim((string) ($payload['reference'] ?? '')) ?: null,
                'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
                'updated_by' => $actorId,
            ]);
            $locked->items()->delete();
            $this->replaceItems($locked, $payload['items'] ?? []);
            $this->auditLog->log('delivery_note.updated', $actorId, $locked);

            return $locked->fresh(['items', 'customer']);
        });
    }

    public function issue(DeliveryNote $note, int $actorId): DeliveryNote
    {
        return DB::transaction(function () use ($note, $actorId): DeliveryNote {
            $locked = DeliveryNote::with('items')->whereKey($note->id)->lockForUpdate()->firstOrFail();
            $this->assertBranchAccess((int) $locked->branch_id, $actorId);
            if ($locked->status !== 'draft') {
                return $locked;
            }
            if ($locked->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => __('Add at least one delivery item.')]);
            }
            $year = $locked->delivery_date->format('Y');
            $sequence = $this->sequences->next('delivery_note', (int) $locked->branch_id, $year);
            $locked->update([
                'delivery_note_number' => sprintf('DN-%s-%06d', $year, $sequence),
                'status' => 'issued',
                'issued_at' => now(),
                'issued_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $this->auditLog->log('delivery_note.issued', $actorId, $locked);

            return $locked->fresh(['items', 'customer']);
        });
    }

    public function createFromInvoice(ArInvoice $invoice, int $actorId): DeliveryNote
    {
        return DB::transaction(function () use ($invoice, $actorId): DeliveryNote {
            $locked = ArInvoice::with(['items', 'customer'])->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertBranchAccess((int) $locked->branch_id, $actorId);
            if (! in_array($locked->status, ['issued', 'partially_paid', 'paid'], true) || $locked->type !== 'invoice') {
                throw ValidationException::withMessages(['invoice' => __('Only issued sales invoices can generate a delivery note.')]);
            }
            if ($locked->source_delivery_note_id) {
                return DeliveryNote::with(['items', 'customer'])->findOrFail($locked->source_delivery_note_id);
            }
            $existing = DeliveryNote::where('source_invoice_id', $locked->id)->first();
            if ($existing) {
                return $existing->load(['items', 'customer']);
            }
            $note = DeliveryNote::create([
                'branch_id' => $locked->branch_id,
                'company_id' => $locked->company_id ?: $this->accountingContext->resolveCompanyId((int) $locked->branch_id),
                'customer_id' => $locked->customer_id,
                'source_invoice_id' => $locked->id,
                'status' => 'draft',
                'delivery_date' => $locked->issue_date ?: now()->toDateString(),
                'customer_name_snapshot' => $locked->customer?->name ?? __('Customer'),
                'delivery_address_snapshot' => $locked->customer?->delivery_address,
                'reference' => $locked->invoice_number,
                'notes' => $locked->notes,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            foreach ($locked->items as $item) {
                DeliveryNoteItem::create([
                    'delivery_note_id' => $note->id,
                    'description' => $item->description,
                    'qty' => $item->qty,
                    'unit' => $item->unit,
                    'unit_price_cents' => $item->unit_price_cents,
                    'discount_cents' => $item->discount_cents,
                    'tax_cents' => $item->tax_cents,
                    'sellable_type' => $item->sellable_type,
                    'sellable_id' => $item->sellable_id,
                    'name_snapshot' => $item->name_snapshot,
                    'sku_snapshot' => $item->sku_snapshot,
                    'line_notes' => $item->line_notes,
                    'meta' => $item->meta,
                ]);
            }

            return $this->issue($note, $actorId);
        });
    }

    public function createInvoice(DeliveryNote $note, int $actorId): ArInvoice
    {
        return DB::transaction(function () use ($note, $actorId): ArInvoice {
            $locked = DeliveryNote::with('items')->whereKey($note->id)->lockForUpdate()->firstOrFail();
            $this->assertBranchAccess((int) $locked->branch_id, $actorId);
            if ($locked->status !== 'issued') {
                throw ValidationException::withMessages(['delivery_note' => __('Issue the delivery note before creating an invoice.')]);
            }
            $existing = ArInvoice::where('source_delivery_note_id', $locked->id)->first();
            if ($existing) {
                return $existing->load('items');
            }
            $items = $locked->items->map(function (DeliveryNoteItem $item): array {
                $quantity = MinorUnits::parseQtyMilli((string) $item->qty);
                $lineTotal = MinorUnits::mulQty($item->unit_price_cents, $quantity) - $item->discount_cents + $item->tax_cents;

                return [
                    'description' => $item->description,
                    'qty' => (string) $item->qty,
                    'unit' => $item->unit,
                    'unit_price_cents' => $item->unit_price_cents,
                    'discount_cents' => $item->discount_cents,
                    'tax_cents' => $item->tax_cents,
                    'line_total_cents' => $lineTotal,
                    'sellable_type' => $item->sellable_type,
                    'sellable_id' => $item->sellable_id,
                    'name_snapshot' => $item->name_snapshot,
                    'sku_snapshot' => $item->sku_snapshot,
                    'line_notes' => $item->line_notes,
                    'meta' => $item->meta,
                ];
            })->all();
            $invoice = $this->invoices->createDraft(
                branchId: (int) $locked->branch_id,
                customerId: (int) $locked->customer_id,
                items: $items,
                actorId: $actorId,
                source: 'delivery_note',
                issueDate: $locked->delivery_date->toDateString(),
            );
            $invoice->update([
                'source_delivery_note_id' => $locked->id,
                'company_id' => $locked->company_id,
                'notes' => $locked->notes,
                'updated_by' => $actorId,
            ]);
            $this->auditLog->log('delivery_note.invoice_created', $actorId, $locked, ['invoice_id' => $invoice->id]);

            return $invoice->fresh('items');
        });
    }

    private function replaceItems(DeliveryNote $note, array $items): void
    {
        $created = 0;
        foreach ($items as $row) {
            $description = trim((string) ($row['description'] ?? ''));
            $qty = round((float) ($row['qty'] ?? 0), 3);
            if ($description === '' || $qty <= 0) {
                continue;
            }
            DeliveryNoteItem::create([
                'delivery_note_id' => $note->id,
                'description' => $description,
                'qty' => $qty,
                'unit' => trim((string) ($row['unit'] ?? '')) ?: null,
                'unit_price_cents' => max(0, (int) ($row['unit_price_cents'] ?? 0)),
                'discount_cents' => max(0, (int) ($row['discount_cents'] ?? 0)),
                'tax_cents' => max(0, (int) ($row['tax_cents'] ?? 0)),
                'line_notes' => trim((string) ($row['line_notes'] ?? '')) ?: null,
                'sellable_type' => $row['sellable_type'] ?? null,
                'sellable_id' => $row['sellable_id'] ?? null,
                'name_snapshot' => $row['name_snapshot'] ?? $description,
                'sku_snapshot' => $row['sku_snapshot'] ?? null,
                'meta' => $row['meta'] ?? null,
            ]);
            $created++;
        }
        if ($created === 0) {
            throw ValidationException::withMessages(['items' => __('Add at least one item with a positive quantity.')]);
        }
    }

    private function assertBranchAccess(int $branchId, int $actorId): void
    {
        $user = User::find($actorId);
        if (! $user || $branchId <= 0 || ! in_array($branchId, $user->allowedBranchIds(), true)) {
            throw ValidationException::withMessages(['branch_id' => __('You do not have access to this branch.')]);
        }
    }
}

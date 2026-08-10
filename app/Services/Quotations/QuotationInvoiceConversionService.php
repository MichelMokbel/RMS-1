<?php

namespace App\Services\Quotations;

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Quotation;
use App\Models\QuotationStatusEvent;
use App\Models\QuotationVersion;
use App\Models\User;
use App\Services\AR\ArInvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationInvoiceConversionService
{
    public function __construct(
        private readonly QuotationDraftService $drafts,
        private readonly ArInvoiceService $invoices,
    ) {}

    public function convert(User $actor, Quotation $quotation, ?int $customerId = null): ArInvoice
    {
        $this->drafts->assertAccessible($actor, $quotation);

        if (! $actor->hasAnyRole(['admin', 'manager']) && ! $actor->can('quotations.convert')) {
            throw ValidationException::withMessages(['quotation' => __('You are not allowed to convert quotations.')]);
        }

        return DB::transaction(function () use ($actor, $quotation, $customerId): ArInvoice {
            $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            $this->drafts->assertAccessible($actor, $locked);

            $existing = ArInvoice::query()->where('source_quotation_id', $locked->id)->first();
            if ($existing) {
                if (! $locked->converted_invoice_id) {
                    $locked->forceFill([
                        'converted_invoice_id' => $existing->id,
                        'converted_at' => $locked->converted_at ?? now(),
                        'updated_by' => $actor->id,
                    ])->save();
                }

                return $existing->load('items');
            }

            if (! $locked->isAccepted()) {
                $this->fail('quotation', __('Only an accepted quotation can be converted.'));
            }
            if ($locked->converted_invoice_id) {
                return ArInvoice::query()->with('items')->findOrFail($locked->converted_invoice_id);
            }

            $version = QuotationVersion::query()
                ->where('quotation_id', $locked->id)
                ->where('revision', $locked->current_revision)
                ->lockForUpdate()
                ->first();
            if (! $version) {
                $this->fail('quotation', __('The accepted quotation version is missing.'));
            }

            // Customer-backed quotations always reuse that customer. Only prospect
            // snapshots may select/create a customer during conversion.
            $resolvedCustomerId = $locked->customer_id ?: $customerId;
            $customer = $resolvedCustomerId ? Customer::query()->active()->find($resolvedCustomerId) : null;
            if (! $customer) {
                $this->fail('customer_id', __('Select or create an active customer before conversion.'));
            }

            $snapshot = (array) $version->snapshot;
            $items = collect(data_get($snapshot, 'items', []))->map(function (array $item): array {
                $menuItemId = isset($item['menu_item_id']) ? (int) $item['menu_item_id'] : null;

                return [
                    'description' => (string) ($item['description'] ?? ''),
                    'qty' => (string) ($item['quantity'] ?? '0.000'),
                    'unit' => $item['unit'] ?? null,
                    'unit_price_cents' => (int) ($item['unit_price_cents'] ?? $item['unit_price_minor'] ?? 0),
                    'discount_cents' => (int) ($item['discount_cents'] ?? $item['line_discount_minor'] ?? 0),
                    'tax_cents' => 0,
                    'line_total_cents' => (int) ($item['line_total_cents'] ?? $item['line_total_minor'] ?? 0),
                    'sellable_type' => $menuItemId ? MenuItem::class : null,
                    'sellable_id' => $menuItemId,
                    'name_snapshot' => (string) ($item['description'] ?? ''),
                    'sku_snapshot' => data_get($item, 'catalog_snapshot.code'),
                    'meta' => ['quotation_catalog_snapshot' => $item['catalog_snapshot'] ?? null],
                ];
            })->values()->all();

            if ($items === []) {
                $this->fail('quotation', __('The accepted quotation contains no items.'));
            }

            $discountType = data_get($snapshot, 'quotation.quotation_discount_type');
            $invoice = $this->invoices->createDraft(
                branchId: (int) $locked->branch_id,
                customerId: (int) $customer->id,
                items: $items,
                actorId: (int) $actor->id,
                currency: 'QAR',
                posReference: $version->quotation_number,
                source: 'quotation',
                type: 'invoice',
                issueDate: now()->toDateString(),
                paymentType: 'credit',
                paymentTermDays: max(0, (int) $customer->credit_terms_days),
                invoiceDiscountType: $discountType === 'percentage' ? 'percent' : 'fixed',
                invoiceDiscountValue: $discountType ? (int) data_get($snapshot, 'quotation.quotation_discount_value', 0) : 0,
            );

            if ((int) $invoice->total_cents !== (int) $version->total_cents || (int) $invoice->tax_total_cents !== 0) {
                $this->fail('quotation', __('The invoice totals do not match the accepted quotation snapshot.'));
            }

            $invoice->forceFill([
                'company_id' => $locked->company_id,
                'source_quotation_id' => $locked->id,
                'source_quotation_version_id' => $version->id,
                'notes' => __('Created from quotation :number revision :revision.', [
                    'number' => $version->quotation_number,
                    'revision' => $version->revision,
                ]),
                'updated_by' => $actor->id,
            ])->save();

            $locked->forceFill([
                'converted_invoice_id' => $invoice->id,
                'converted_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            QuotationStatusEvent::query()->create([
                'quotation_id' => $locked->id,
                'quotation_version_id' => $version->id,
                'event' => 'converted',
                'from_status' => Quotation::STATUS_ACCEPTED,
                'to_status' => Quotation::STATUS_ACCEPTED,
                'actor_id' => $actor->id,
                'metadata' => ['invoice_id' => $invoice->id],
            ]);

            return $invoice->fresh('items');
        }, 3);
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}

<?php

namespace App\Services\AR;

use App\Events\InvoiceIssued;
use App\Models\ArInvoice;
use App\Models\ArInvoiceItem;
use App\Models\Customer;
use App\Models\PaymentTerm;
use App\Models\Sale;
use App\Services\Sequences\DocumentSequenceService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArInvoiceService
{
    public function __construct(protected DocumentSequenceService $sequences)
    {
    }

    public function createDraft(
        int $branchId,
        int $customerId,
        array $items,
        int $actorId,
        string $currency = 'KWD',
        ?int $sourceSaleId = null,
        string $type = 'invoice',
        ?string $issueDate = null,
        ?string $paymentType = null,
        ?int $paymentTermId = null,
        int $paymentTermDays = 0,
        ?int $salesPersonId = null,
        ?string $lpoReference = null,
        string $invoiceDiscountType = 'fixed',
        int $invoiceDiscountValue = 0,
    ): ArInvoice
    {
        if ($branchId <= 0) {
            $branchId = 1;
        }
        if ($customerId <= 0) {
            throw ValidationException::withMessages(['customer_id' => __('Customer is required.')]);
        }

        $customer = Customer::find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => __('Customer not found.')]);
        }

        return DB::transaction(function () use ($branchId, $customer, $items, $actorId, $currency, $sourceSaleId, $type, $issueDate, $paymentType, $paymentTermId, $paymentTermDays, $salesPersonId, $lpoReference, $invoiceDiscountType, $invoiceDiscountValue) {
            $termDays = max(0, $paymentTermDays);
            if ($paymentTermId) {
                $term = PaymentTerm::find($paymentTermId);
                if ($term) {
                    $termDays = (int) $term->days;
                }
            }

            $invoice = ArInvoice::create([
                'branch_id' => $branchId,
                'customer_id' => $customer->id,
                'source_sale_id' => $sourceSaleId,
                'type' => $type,
                'invoice_number' => null,
                'status' => 'draft',
                'payment_type' => $paymentType,
                'payment_term_id' => $paymentTermId,
                'payment_term_days' => $termDays,
                'sales_person_id' => $salesPersonId,
                'lpo_reference' => $lpoReference,
                'issue_date' => $issueDate,
                'due_date' => $issueDate ? now()->parse($issueDate)->addDays($termDays)->toDateString() : null,
                'currency' => $currency,
                'subtotal_cents' => 0,
                'discount_total_cents' => 0,
                'invoice_discount_type' => $invoiceDiscountType === 'percent' ? 'percent' : 'fixed',
                'invoice_discount_value' => max(0, $invoiceDiscountValue),
                'invoice_discount_cents' => 0,
                'tax_total_cents' => 0,
                'total_cents' => 0,
                'paid_total_cents' => 0,
                'balance_cents' => 0,
                'notes' => null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            foreach ($items as $row) {
                $desc = (string) ($row['description'] ?? '');
                $qty = (string) ($row['qty'] ?? '1.000');
                $unit = (int) ($row['unit_price_cents'] ?? 0);
                $discount = (int) ($row['discount_cents'] ?? 0);
                $tax = (int) ($row['tax_cents'] ?? 0);
                $line = (int) ($row['line_total_cents'] ?? ($unit - $discount + $tax));

                if (trim($desc) === '') {
                    continue;
                }

                ArInvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $desc,
                    'qty' => $qty,
                    'unit' => $row['unit'] ?? null,
                    'unit_price_cents' => $unit,
                    'discount_cents' => $discount,
                    'tax_cents' => $tax,
                    'line_total_cents' => $line,
                    'line_notes' => $row['line_notes'] ?? null,
                    'sellable_type' => $row['sellable_type'] ?? null,
                    'sellable_id' => $row['sellable_id'] ?? null,
                    'name_snapshot' => $row['name_snapshot'] ?? null,
                    'sku_snapshot' => $row['sku_snapshot'] ?? null,
                    'meta' => $row['meta'] ?? null,
                ]);
            }

            $this->recalc($invoice->fresh(['items']));

            return $invoice->fresh(['items']);
        });
    }

    public function createFromSale(Sale $sale, int $actorId, ?string $notes = null): ArInvoice
    {
        if (! $sale->customer_id) {
            throw ValidationException::withMessages(['customer_id' => __('Customer is required to invoice a sale.')]);
        }

        $sale = $sale->fresh(['items']);
        $items = $sale->items->map(function ($i) {
            return [
                'description' => $i->name_snapshot,
                'qty' => (string) $i->qty,
                'unit_price_cents' => (int) $i->unit_price_cents,
                'discount_cents' => (int) $i->discount_cents,
                'tax_cents' => (int) $i->tax_cents,
                'line_total_cents' => (int) $i->line_total_cents,
                'sellable_type' => $i->sellable_type,
                'sellable_id' => $i->sellable_id,
                'name_snapshot' => $i->name_snapshot,
                'sku_snapshot' => $i->sku_snapshot,
                'meta' => $i->meta,
            ];
        })->all();

        $invoice = $this->createDraft(
            branchId: (int) $sale->branch_id,
            customerId: (int) $sale->customer_id,
            items: $items,
            actorId: $actorId,
            currency: (string) ($sale->currency ?: 'KWD'),
            sourceSaleId: (int) $sale->id,
            type: 'invoice',
        );

        if ($notes) {
            $invoice->update(['notes' => $notes, 'updated_by' => $actorId]);
        }

        return $invoice->fresh(['items']);
    }

    public function issue(ArInvoice $invoice, int $actorId): ArInvoice
    {
        $invoice = $invoice->fresh(['items', 'customer']);

        if (! $invoice->isDraft()) {
            throw ValidationException::withMessages(['invoice' => __('Invoice is not draft.')]);
        }

        if ($invoice->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => __('Add at least one invoice item.')]);
        }

        return DB::transaction(function () use ($invoice, $actorId) {
            $locked = ArInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['invoice' => __('Invoice is not draft.')]);
            }

            $this->recalc($locked->fresh(['items']));

            $year = now()->format('Y');
            $seq = $this->sequences->next('ar_invoice', (int) $locked->branch_id, $year);
            $locked->invoice_number = 'INV'.$year.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);

            $issueDate = $locked->issue_date ? $locked->issue_date->toDateString() : now()->toDateString();
            $termsDays = (int) ($locked->payment_term_days ?? 0);
            if ($termsDays <= 0 && $locked->payment_term_id) {
                $term = PaymentTerm::find($locked->payment_term_id);
                $termsDays = $term ? (int) $term->days : 0;
            }
            if ($termsDays <= 0) {
                $termsDays = (int) ($locked->customer?->credit_terms_days ?? 0);
            }
            $dueDate = $termsDays > 0 ? now()->parse($issueDate)->addDays($termsDays)->toDateString() : $issueDate;

            $locked->issue_date = $issueDate;
            $locked->due_date = $locked->due_date ?: $dueDate;
            $locked->status = 'issued';
            $locked->updated_by = $actorId;
            $locked->save();

            InvoiceIssued::dispatch($locked->fresh());

            return $locked->fresh(['items']);
        });
    }

    public function recalc(ArInvoice $invoice): ArInvoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = $invoice->fresh(['items', 'paymentAllocations']);

            $subtotal = 0;
            $discount = 0;
            $tax = 0;
            $total = 0;

            foreach ($invoice->items as $item) {
                $qtyMilli = MinorUnits::parseQtyMilli((string) $item->qty);
                $lineSubtotal = MinorUnits::mulQty((int) $item->unit_price_cents, $qtyMilli);
                $subtotal += $lineSubtotal;
                $discount += (int) $item->discount_cents;
                $tax += (int) $item->tax_cents;
                $total += (int) $item->line_total_cents;
            }

            $invoiceDiscount = 0;
            $discountType = $invoice->invoice_discount_type ?? 'fixed';
            $discountValue = (int) ($invoice->invoice_discount_value ?? 0);
            if ($discountType === 'percent') {
                $invoiceDiscount = MinorUnits::percentBps(max(0, $subtotal - $discount), $discountValue);
            } else {
                $invoiceDiscount = $discountValue;
            }
            $invoiceDiscount = max(0, min($invoiceDiscount, $total));
            $total = max(0, $total - $invoiceDiscount);

            $paid = (int) $invoice->paymentAllocations()->sum('amount_cents');
            $balance = $total - $paid;

            $invoice->update([
                'subtotal_cents' => $subtotal,
                'discount_total_cents' => $discount + $invoiceDiscount,
                'invoice_discount_cents' => $invoiceDiscount,
                'tax_total_cents' => $tax,
                'total_cents' => $total,
                'paid_total_cents' => $paid,
                'balance_cents' => $balance,
            ]);

            return $invoice->fresh();
        });
    }
}


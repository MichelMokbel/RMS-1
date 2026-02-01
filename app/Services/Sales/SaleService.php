<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\Money\MinorUnits;
use App\Services\Sequences\DocumentSequenceService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function create(array $data, ?int $actorId): Sale
    {
        $branchId = (int) ($data['branch_id'] ?? 0);
        if ($branchId <= 0) {
            $branchId = 1;
        }

        $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : null;
        if ($customerId !== null && $customerId > 0 && Schema::hasTable('customers')) {
            if (! Customer::whereKey($customerId)->exists()) {
                throw ValidationException::withMessages(['customer_id' => __('Customer not found.')]);
            }
        } else {
            $customerId = null;
        }

        $shiftId = isset($data['pos_shift_id']) ? (int) $data['pos_shift_id'] : null;
        $currency = (string) ($data['currency'] ?? config('pos.currency'));
        $orderType = isset($data['order_type']) && in_array($data['order_type'], ['takeaway', 'dine_in'], true)
            ? $data['order_type'] : 'takeaway';
        $reference = isset($data['reference']) ? trim((string) $data['reference']) : null;
        if ($reference === '') {
            $reference = null;
        }
        $source = (string) ($data['source'] ?? '');
        $posDate = $data['pos_date'] ?? now()->toDateString();

        return DB::transaction(function () use ($branchId, $shiftId, $customerId, $currency, $orderType, $reference, $posDate, $source, $actorId) {
            $posReference = null;
            if ($source === 'pos') {
                /** @var DocumentSequenceService $sequences */
                $sequences = app(DocumentSequenceService::class);
                $year = now()->format('Y');
                $seq = $sequences->next('pos_sale', $branchId, $year);
                $posReference = 'POS'.$year.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
            }

            return Sale::create([
                'branch_id' => $branchId,
                'pos_shift_id' => $shiftId ?: null,
                'customer_id' => $customerId,
                'sale_number' => null,
                'status' => 'open',
                'order_type' => $orderType,
                'currency' => $currency,
                'subtotal_cents' => 0,
                'discount_total_cents' => 0,
                'global_discount_cents' => 0,
                'global_discount_type' => 'fixed',
                'global_discount_value' => 0,
                'is_credit' => false,
                'pos_date' => $posDate,
                'tax_total_cents' => 0,
                'total_cents' => 0,
                'paid_total_cents' => 0,
                'due_total_cents' => 0,
                'notes' => null,
                'reference' => $reference,
                'pos_reference' => $posReference,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        });
    }

    public function addMenuItem(Sale $sale, MenuItem $item, string $qty = '1', int $discountCents = 0, ?int $unitPriceCents = null): SaleItem
    {
        if (! $sale->isOpen()) {
            throw ValidationException::withMessages(['sale' => __('Sale is not open.')]);
        }

        $qtyMilli = MinorUnits::parseQtyMilli($qty);
        if ($qtyMilli <= 0) {
            throw ValidationException::withMessages(['qty' => __('Quantity must be positive.')]);
        }

        $unitPriceCents = $unitPriceCents ?? MinorUnits::parsePos((string) ($item->selling_price_per_unit ?? '0'));
        $discountCents = max(0, (int) $discountCents);

        $bps = $this->taxRateToBps((string) ($item->tax_rate ?? '0'));

        return DB::transaction(function () use ($sale, $item, $qtyMilli, $unitPriceCents, $discountCents, $bps) {
            $name = trim(($item->code ?? '').' '.($item->name ?? ''));

            $existing = SaleItem::query()
                ->where('sale_id', $sale->id)
                ->where('sellable_type', MenuItem::class)
                ->where('sellable_id', $item->id)
                ->first();

            if ($existing) {
                $currentQtyMilli = MinorUnits::parseQtyMilli((string) $existing->qty);
                $newQty = $currentQtyMilli + $qtyMilli;
                $existing->update([
                    'qty' => $this->formatQtyMilli($newQty),
                ]);
                $this->recalc($sale->fresh(['items']));
                return $existing->fresh();
            }

            $row = SaleItem::create([
                'sale_id' => $sale->id,
                'sellable_type' => MenuItem::class,
                'sellable_id' => $item->id,
                'name_snapshot' => $name !== '' ? $name : ($item->name ?? __('Item')),
                'sku_snapshot' => $item->code,
                'tax_rate_bps' => $bps,
                // Stored as decimal(12,3), but computed from integers (no floats).
                'qty' => $this->formatQtyMilli($qtyMilli),
                'unit_price_cents' => $unitPriceCents,
                'discount_cents' => $discountCents,
                'discount_type' => 'fixed',
                'discount_value' => $discountCents,
                'tax_cents' => 0,
                'line_total_cents' => 0,
                'meta' => null,
            ]);

            $this->recalc($sale->fresh(['items']));

            return $row->fresh();
        });
    }

    public function recalc(Sale $sale): Sale
    {
        return DB::transaction(function () use ($sale) {
            $sale = $sale->fresh(['items', 'paymentAllocations']);

            $subtotal = 0;
            $discount = 0;
            $tax = 0;
            $total = 0;

            foreach ($sale->items as $item) {
                $qtyMilli = MinorUnits::parseQtyMilli((string) $item->qty);
                $lineSubtotal = MinorUnits::mulQty((int) $item->unit_price_cents, $qtyMilli);
                $discountType = $item->discount_type ?? 'fixed';
                $discountValue = (int) ($item->discount_value ?? 0);
                if ($discountValue === 0 && (int) $item->discount_cents > 0 && $discountType === 'fixed') {
                    $discountValue = (int) $item->discount_cents;
                }
                if ($discountType === 'percent') {
                    $lineDiscount = MinorUnits::percentBps($lineSubtotal, $discountValue);
                } else {
                    $lineDiscount = $discountValue;
                }
                $lineDiscount = max(0, min($lineDiscount, $lineSubtotal));
                $lineNet = max(0, $lineSubtotal - $lineDiscount);
                $lineTax = MinorUnits::percentBps($lineNet, (int) $item->tax_rate_bps);
                $lineTotal = $lineNet + $lineTax;

                $item->update([
                    'discount_cents' => $lineDiscount,
                    'tax_cents' => $lineTax,
                    'line_total_cents' => $lineTotal,
                ]);

                $subtotal += $lineSubtotal;
                $discount += (int) $lineDiscount;
                $tax += $lineTax;
                $total += $lineTotal;
            }

            $globalType = $sale->global_discount_type ?? 'fixed';
            $globalValue = (int) ($sale->global_discount_value ?? 0);
            $discountBase = max(0, $subtotal - $discount);
            if ($globalType === 'percent') {
                $globalDiscount = MinorUnits::percentBps($discountBase, $globalValue);
            } else {
                $globalDiscount = $globalValue;
            }
            $globalDiscount = max(0, min($globalDiscount, $total));
            $total = max(0, $total - $globalDiscount);

            $paid = (int) $sale->paymentAllocations()->sum('amount_cents');
            $due = max(0, $total - $paid);

            $sale->update([
                'subtotal_cents' => $subtotal,
                'discount_total_cents' => $discount,
                'global_discount_cents' => $globalDiscount,
                'tax_total_cents' => $tax,
                'total_cents' => $total,
                'paid_total_cents' => $paid,
                'due_total_cents' => $due,
            ]);

            return $sale->fresh();
        });
    }

    public function setGlobalDiscount(Sale $sale, int $globalDiscountCents): Sale
    {
        if (! $sale->isOpen()) {
            throw ValidationException::withMessages(['sale' => __('Sale is not open.')]);
        }
        $globalDiscountCents = max(0, $globalDiscountCents);

        return DB::transaction(function () use ($sale, $globalDiscountCents) {
            $sale->update([
                'global_discount_type' => 'fixed',
                'global_discount_value' => $globalDiscountCents,
                'global_discount_cents' => $globalDiscountCents,
            ]);
            return $this->recalc($sale->fresh(['items', 'paymentAllocations']));
        });
    }

    public function setGlobalDiscountValue(Sale $sale, string $type, string $value): Sale
    {
        if (! $sale->isOpen()) {
            throw ValidationException::withMessages(['sale' => __('Sale is not open.')]);
        }
        $type = $type === 'percent' ? 'percent' : 'fixed';
        $storedValue = $type === 'percent'
            ? $this->parsePercentToBps($value)
            : MinorUnits::parsePos($value);

        return DB::transaction(function () use ($sale, $type, $storedValue) {
            $sale->update([
                'global_discount_type' => $type,
                'global_discount_value' => max(0, $storedValue),
            ]);
            return $this->recalc($sale->fresh(['items', 'paymentAllocations']));
        });
    }

    public function updateItemQty(SaleItem $item, string $qty): SaleItem
    {
        $qtyMilli = MinorUnits::parseQtyMilli($qty);
        if ($qtyMilli <= 0) {
            throw ValidationException::withMessages(['qty' => __('Quantity must be positive.')]);
        }
        $item->update(['qty' => $this->formatQtyMilli($qtyMilli)]);
        $this->recalc($item->sale->fresh(['items', 'paymentAllocations']));
        return $item->fresh();
    }

    public function updateItemPrice(SaleItem $item, string $price): SaleItem
    {
        $priceCents = MinorUnits::parsePos($price);
        if ($priceCents < 0) {
            throw ValidationException::withMessages(['price' => __('Price must be non-negative.')]);
        }
        $item->update(['unit_price_cents' => $priceCents]);
        $this->recalc($item->sale->fresh(['items', 'paymentAllocations']));
        return $item->fresh();
    }

    public function updateItemDiscount(SaleItem $item, string $type, string $value): SaleItem
    {
        $type = $type === 'percent' ? 'percent' : 'fixed';
        $storedValue = $type === 'percent'
            ? $this->parsePercentToBps($value)
            : MinorUnits::parsePos($value);

        $item->update([
            'discount_type' => $type,
            'discount_value' => max(0, $storedValue),
        ]);
        $this->recalc($item->sale->fresh(['items', 'paymentAllocations']));
        return $item->fresh();
    }

    public function hold(Sale $sale, int $userId): Sale
    {
        if (! $sale->isOpen()) {
            throw ValidationException::withMessages(['sale' => __('Sale is not open.')]);
        }
        if ($sale->held_at !== null) {
            return $sale->fresh();
        }

        return DB::transaction(function () use ($sale, $userId) {
            $sale->update([
                'held_at' => now(),
                'held_by' => $userId,
                'updated_by' => $userId,
            ]);
            return $sale->fresh();
        });
    }

    public function recall(Sale $sale, int $userId): Sale
    {
        if ($sale->held_at === null) {
            return $sale->fresh();
        }

        return DB::transaction(function () use ($sale, $userId) {
            $sale->update([
                'held_at' => null,
                'held_by' => null,
                'updated_by' => $userId,
            ]);
            return $sale->fresh();
        });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Sale>
     */
    public function listHeld(int $branchId): \Illuminate\Database\Eloquent\Collection
    {
        return Sale::query()
            ->where('branch_id', $branchId)
            ->whereNotNull('held_at')
            ->whereIn('status', ['draft', 'open'])
            ->orderByDesc('held_at')
            ->get();
    }

    public function void(Sale $sale, User $actor, string $reason): Sale
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['void_reason' => __('Void reason is required.')]);
        }

        Gate::forUser($actor)->authorize('sale.void', $sale);

        return DB::transaction(function () use ($sale, $actor, $reason) {
            $locked = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'voided') {
                return $locked->fresh();
            }
            if ($locked->status === 'closed') {
                throw ValidationException::withMessages(['sale' => __('Closed sales cannot be voided. Use a refund/credit flow.')]);
            }

            $locked->update([
                'status' => 'voided',
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
                'updated_by' => $actor->id,
            ]);

            return $locked->fresh();
        });
    }

    private function taxRateToBps(string $taxRatePercent): int
    {
        $taxRatePercent = trim($taxRatePercent);
        if ($taxRatePercent === '') {
            return 0;
        }

        // e.g. "5.25" percent => 525 bps
        $negative = str_starts_with($taxRatePercent, '-');
        if ($negative) {
            $taxRatePercent = ltrim($taxRatePercent, '-');
        }

        $taxRatePercent = str_replace(',', '', $taxRatePercent);
        if (! preg_match('/^\d+(\.\d+)?$/', $taxRatePercent)) {
            return 0;
        }

        [$whole, $frac] = array_pad(explode('.', $taxRatePercent, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $frac = substr(str_pad($frac, 2, '0', STR_PAD_RIGHT), 0, 2);
        $bps = ((int) $whole) * 100 + (int) $frac;

        return $negative ? -$bps : $bps;
    }

    private function parsePercentToBps(string $percent): int
    {
        $percent = trim($percent);
        if ($percent === '') {
            return 0;
        }
        $negative = str_starts_with($percent, '-');
        if ($negative) {
            $percent = ltrim($percent, '-');
        }
        $percent = str_replace(',', '', $percent);
        if (! preg_match('/^\d+(\.\d+)?$/', $percent)) {
            return 0;
        }
        [$whole, $frac] = array_pad(explode('.', $percent, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $frac = substr(str_pad($frac, 2, '0', STR_PAD_RIGHT), 0, 2);
        $bps = ((int) $whole) * 100 + (int) $frac;
        return $negative ? -$bps : $bps;
    }

    private function formatQtyMilli(int $qtyMilli): string
    {
        $sign = $qtyMilli < 0 ? '-' : '';
        $qtyMilli = abs($qtyMilli);
        $whole = intdiv($qtyMilli, 1000);
        $frac = $qtyMilli % 1000;

        return $sign.$whole.'.'.str_pad((string) $frac, 3, '0', STR_PAD_LEFT);
    }
}


<?php

namespace App\Services\Purchasing;

use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderPersistService
{
    public function create(array $data, string $status, ?int $actorId): PurchaseOrder
    {
        $actorId = ($actorId && $actorId > 0) ? $actorId : null;

        return DB::transaction(function () use ($data, $status, $actorId) {
            $po = PurchaseOrder::create([
                'po_number' => $data['po_number'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'order_date' => $data['order_date'] ?? null,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'payment_type' => $data['payment_type'] ?? null,
                'status' => $status,
                'created_by' => $actorId,
            ]);

            $total = 0.0;
            foreach (($data['lines'] ?? []) as $line) {
                $lineTotal = (float) $line['quantity'] * (float) $line['unit_price'];
                $total += $lineTotal;
                $po->items()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total_price' => $lineTotal,
                    'received_quantity' => 0,
                ]);
            }

            $po->update(['total_amount' => $total]);

            return $po->fresh(['items']);
        });
    }

    public function update(PurchaseOrder $po, array $data, string $status): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $data, $status) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();

            if (! $po->canEditLines()) {
                throw ValidationException::withMessages(['status' => __('Purchase order is not editable in the current status.')]);
            }

            $po->update([
                'po_number' => $data['po_number'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'order_date' => $data['order_date'] ?? null,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'payment_type' => $data['payment_type'] ?? null,
                'status' => $status,
            ]);

            $po->items()->delete();

            $total = 0.0;
            foreach (($data['lines'] ?? []) as $line) {
                $lineTotal = (float) $line['quantity'] * (float) $line['unit_price'];
                $total += $lineTotal;
                $po->items()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total_price' => $lineTotal,
                    'received_quantity' => 0,
                ]);
            }

            $po->update(['total_amount' => $total]);

            return $po->fresh(['items']);
        });
    }
}


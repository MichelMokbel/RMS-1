<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrderReceiveRequest;
use App\Http\Requests\PurchaseOrderStoreRequest;
use App\Http\Requests\PurchaseOrderUpdateRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Purchasing\PurchaseOrderNumberService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseOrder::query()
            ->with(['supplier', 'creator'])
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where('po_number', 'like', '%'.$request->search.'%'))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('order_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('order_date', '<=', $request->date_to))
            ->orderByDesc('order_date')
            ->orderByDesc('id');

        return response()->json(
            $query->paginate($request->integer('per_page', 15))
        );
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json(
            $purchaseOrder->load(['items.item', 'supplier', 'creator'])
        );
    }

    public function store(
        PurchaseOrderStoreRequest $request,
        PurchaseOrderNumberService $numberService
    ): JsonResponse {
        $data = $request->validated();

        if (empty($data['po_number'])) {
            $data['po_number'] = $numberService->generate();
        }

        $po = DB::transaction(function () use ($data) {
            $po = PurchaseOrder::create([
                'po_number' => $data['po_number'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'order_date' => $data['order_date'] ?? null,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'payment_type' => $data['payment_type'] ?? null,
                'status' => $data['status'],
                'created_by' => auth()->id(),
            ]);

            $total = 0;
            foreach ($data['lines'] as $line) {
                $line['total_price'] = (float) $line['quantity'] * (float) $line['unit_price'];
                $total += $line['total_price'];
                $po->items()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total_price' => $line['total_price'],
                    'received_quantity' => 0,
                ]);
            }

            $po->update(['total_amount' => $total]);

            return $po;
        });

        return response()->json($po->load('items'), 201);
    }

    public function update(
        PurchaseOrderUpdateRequest $request,
        PurchaseOrder $purchaseOrder
    ): JsonResponse {
        $po = $purchaseOrder->load('items');

        if (! $po->canEditLines()) {
            throw ValidationException::withMessages([
                'status' => __('Cannot edit a purchase order once approved/received/cancelled.'),
            ]);
        }

        $data = $request->validated();

        $po = DB::transaction(function () use ($po, $data) {
            $po->update([
                'po_number' => $data['po_number'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'order_date' => $data['order_date'] ?? null,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'payment_type' => $data['payment_type'] ?? null,
                'status' => $data['status'],
            ]);

            if (isset($data['lines'])) {
                $po->items()->delete();
                $total = 0;
                foreach ($data['lines'] as $line) {
                    $line['total_price'] = (float) $line['quantity'] * (float) $line['unit_price'];
                    $total += $line['total_price'];
                    $po->items()->create([
                        'item_id' => $line['item_id'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'total_price' => $line['total_price'],
                        'received_quantity' => 0,
                    ]);
                }
                $po->update(['total_amount' => $total]);
            }

            return $po;
        });

        return response()->json($po->fresh('items'));
    }

    public function submit(PurchaseOrder $purchaseOrder): JsonResponse
    {
        if (! $purchaseOrder->isDraft()) {
            throw ValidationException::withMessages(['status' => __('Only drafts can be submitted.')]);
        }
        if ($purchaseOrder->items()->count() === 0) {
            throw ValidationException::withMessages(['lines' => __('Add at least one line before submitting.')]);
        }

        $purchaseOrder->update(['status' => PurchaseOrder::STATUS_PENDING]);

        return response()->json($purchaseOrder->fresh());
    }

    public function approve(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load('items');
        if (! $purchaseOrder->isPending()) {
            throw ValidationException::withMessages(['status' => __('Only pending purchase orders can be approved.')]);
        }
        if (! $purchaseOrder->supplier_id) {
            throw ValidationException::withMessages(['supplier_id' => __('Supplier is required to approve.')]);
        }
        if ($purchaseOrder->items->isEmpty()) {
            throw ValidationException::withMessages(['lines' => __('Add at least one line before approving.')]);
        }

        $purchaseOrder->update(['status' => PurchaseOrder::STATUS_APPROVED]);

        return response()->json($purchaseOrder->fresh());
    }

    public function receive(
        PurchaseOrderReceiveRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderReceivingService $receivingService
    ): JsonResponse {
        $validated = $request->validated();
        $receipts = $validated['receipts'];
        $costs = $validated['costs'] ?? [];
        $notes = $validated['notes'] ?? null;

        $po = $receivingService->receive($purchaseOrder, $receipts, auth()->id(), $notes, $costs);

        return response()->json($po);
    }

    public function cancel(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load('items');

        if ($purchaseOrder->isReceived()) {
            throw ValidationException::withMessages(['status' => __('Cannot cancel a received purchase order.')]);
        }

        if ($purchaseOrder->isApproved()) {
            $receivedAny = $purchaseOrder->items->sum('received_quantity') > 0;
            if ($receivedAny) {
                throw ValidationException::withMessages(['status' => __('Cannot cancel after receiving items.')]);
            }
        }

        $purchaseOrder->update(['status' => PurchaseOrder::STATUS_CANCELLED]);

        return response()->json($purchaseOrder->fresh());
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->cancel($purchaseOrder);
    }
}

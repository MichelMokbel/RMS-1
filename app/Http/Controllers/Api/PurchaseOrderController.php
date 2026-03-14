<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseOrderReceiveRequest;
use App\Http\Requests\PurchaseOrderStoreRequest;
use App\Http\Requests\PurchaseOrderUpdateRequest;
use App\Models\PurchaseOrder;
use App\Services\Purchasing\PurchaseOrderNumberService;
use App\Services\Purchasing\PurchaseOrderPersistService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        PurchaseOrderNumberService $numberService,
        PurchaseOrderPersistService $persist
    ): JsonResponse {
        $data = $request->validated();

        if (empty($data['po_number'])) {
            $data['po_number'] = $numberService->generate();
        }

        $po = $persist->create($data, (string) $data['status'], (int) Auth::id());

        return response()->json($po->load('items'), 201);
    }

    public function update(
        PurchaseOrderUpdateRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderPersistService $persist
    ): JsonResponse {
        $po = $purchaseOrder->load('items');

        if (! $po->canEditLines()) {
            throw ValidationException::withMessages([
                'status' => __('Cannot edit this purchase order in the current status.'),
            ]);
        }

        $data = $request->validated();
        $po = $persist->update($po, $data, (string) $data['status']);

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

    public function approve(PurchaseOrder $purchaseOrder, PurchaseOrderWorkflowService $workflowService): JsonResponse
    {
        $purchaseOrder = $workflowService->approve($purchaseOrder, (int) Auth::id());

        return response()->json($purchaseOrder);
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

        $po = $receivingService->receive($purchaseOrder, $receipts, Auth::id(), $notes, $costs);

        return response()->json($po);
    }

    public function cancel(PurchaseOrder $purchaseOrder, PurchaseOrderWorkflowService $workflowService): JsonResponse
    {
        $purchaseOrder = $workflowService->cancel($purchaseOrder, (int) Auth::id());

        return response()->json($purchaseOrder);
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return $this->cancel($purchaseOrder);
    }
}

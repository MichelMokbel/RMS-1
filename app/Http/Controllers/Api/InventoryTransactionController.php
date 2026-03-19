<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryManualTransactionRequest;
use App\Models\InventoryItem;
use App\Services\Inventory\InventoryStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class InventoryTransactionController extends Controller
{
    public function store(InventoryManualTransactionRequest $request, InventoryStockService $stockService): JsonResponse
    {
        $data = $request->validated();
        $item = InventoryItem::query()->findOrFail((int) $data['item_id']);

        $transaction = $stockService->postTransaction(
            $item,
            (string) $data['transaction_type'],
            (float) $data['quantity'],
            $data['notes'] ?? null,
            (int) (Auth::id() ?? 0),
            (int) $data['branch_id'],
            isset($data['unit_cost']) ? (float) $data['unit_cost'] : null,
            'manual',
            null,
            $data['transaction_date']
        );

        return response()->json([
            'message' => __('Transaction recorded.'),
            'transaction' => $transaction->load(['item', 'user']),
            'item' => $item->fresh(),
        ], 201);
    }
}

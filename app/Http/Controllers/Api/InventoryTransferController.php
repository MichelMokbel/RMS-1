<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryTransferRequest;
use App\Models\InventoryItem;
use App\Services\Inventory\InventoryTransferService;

class InventoryTransferController extends Controller
{
    public function store(InventoryTransferRequest $request, InventoryTransferService $service)
    {
        $data = $request->validated();
        $userId = (int) (\Illuminate\Support\Facades\Auth::id() ?? 0);

        if (! empty($data['lines'])) {
            $transfer = $service->createAndPostBulk(
                (int) $data['from_branch_id'],
                (int) $data['to_branch_id'],
                $data['lines'],
                $userId,
                $data['notes'] ?? null,
                $data['transfer_date'] ?? null
            );
        } else {
            $item = InventoryItem::findOrFail($data['item_id']);
            $transfer = $service->createAndPost(
                $item,
                (int) $data['from_branch_id'],
                (int) $data['to_branch_id'],
                (float) $data['quantity'],
                $userId,
                $data['notes'] ?? null,
                $data['transfer_date'] ?? null
            );
        }

        return response()->json($transfer->load('lines'), 201);
    }
}

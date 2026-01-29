<?php

namespace App\Http\Controllers\Api\AP;

use App\Http\Controllers\Controller;
use App\Http\Requests\AP\ApPaymentStoreRequest;
use App\Models\ApPayment;
use App\Services\AP\ApAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ApPayment::query()->with(['supplier', 'allocations.invoice']);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }
        if ($request->filled('payment_date_from')) {
            $query->whereDate('payment_date', '>=', $request->payment_date_from);
        }
        if ($request->filled('payment_date_to')) {
            $query->whereDate('payment_date', '<=', $request->payment_date_to);
        }

        return response()->json($query->orderByDesc('payment_date')->paginate($request->integer('per_page', 15)));
    }

    public function show(ApPayment $payment): JsonResponse
    {
        return response()->json($payment->load(['allocations.invoice', 'supplier']));
    }

    public function store(
        ApPaymentStoreRequest $request,
        ApAllocationService $allocationService
    ): JsonResponse {
        $data = $request->validated();
        $payment = $allocationService->createPaymentWithAllocations($data, Auth::id());

        return response()->json($payment, 201);
    }
}

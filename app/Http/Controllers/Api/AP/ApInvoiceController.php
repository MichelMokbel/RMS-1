<?php

namespace App\Http\Controllers\Api\AP;

use App\Http\Controllers\Controller;
use App\Http\Requests\AP\ApInvoicePostRequest;
use App\Http\Requests\AP\ApInvoiceStoreRequest;
use App\Http\Requests\AP\ApInvoiceUpdateRequest;
use App\Http\Requests\AP\ApInvoiceVoidRequest;
use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Services\AP\ApInvoicePostingService;
use App\Services\AP\ApInvoiceTotalsService;
use App\Services\AP\ApInvoiceVoidService;
use App\Services\AP\ApInvoiceStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApInvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ApInvoice::query()
            ->with(['supplier', 'allocations'])
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('invoice_number'), fn ($q) => $q->where('invoice_number', 'like', '%'.$request->invoice_number.'%'))
            ->orderByDesc('invoice_date');

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function show(ApInvoice $invoice): JsonResponse
    {
        return response()->json($invoice->load(['items', 'allocations.payment', 'supplier']));
    }

    public function store(
        ApInvoiceStoreRequest $request,
        ApInvoiceTotalsService $totalsService
    ): JsonResponse {
        $data = $request->validated();

        $invoice = DB::transaction(function () use ($data, $totalsService) {
            $invoice = ApInvoice::create([
                'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'is_expense' => $data['is_expense'],
                'invoice_number' => $data['invoice_number'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'subtotal' => 0,
                'tax_amount' => $data['tax_amount'],
                'total_amount' => 0,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => Illuminate\Support\Facades\Auth::id(),
            ]);

            foreach ($data['items'] as $item) {
                $lineTotal = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
                ApInvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                ]);
            }

            $totalsService->recalc($invoice);

            return $invoice;
        });

        return response()->json($invoice->load(['items']), 201);
    }

    public function update(
        ApInvoiceUpdateRequest $request,
        ApInvoice $invoice,
        ApInvoiceTotalsService $totalsService
    ): JsonResponse {
        $data = $request->validated();

        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages(['status' => __('Only draft invoices can be edited.')]);
        }

        $invoice = DB::transaction(function () use ($invoice, $data, $totalsService) {
            $invoice->update([
                'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'is_expense' => $data['is_expense'],
                'invoice_number' => $data['invoice_number'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'tax_amount' => $data['tax_amount'],
                'notes' => $data['notes'] ?? null,
            ]);

            $invoice->items()->delete();
            foreach ($data['items'] as $item) {
                $lineTotal = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
                ApInvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                ]);
            }

            $totalsService->recalc($invoice);

            return $invoice;
        });

        return response()->json($invoice->load(['items']));
    }

    public function post(
        ApInvoicePostRequest $request,
        ApInvoice $invoice,
        ApInvoicePostingService $postingService
    ): JsonResponse {
        $invoice = $postingService->post($invoice, Illuminate\Support\Facades\Auth::id());

        return response()->json($invoice);
    }

    public function void(
        ApInvoiceVoidRequest $request,
        ApInvoice $invoice,
        ApInvoiceVoidService $voidService
    ): JsonResponse {
        $invoice = $voidService->void($invoice, Illuminate\Support\Facades\Auth::id());

        return response()->json($invoice);
    }
}

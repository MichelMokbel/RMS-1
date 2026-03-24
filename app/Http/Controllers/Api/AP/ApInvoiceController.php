<?php

namespace App\Http\Controllers\Api\AP;

use App\Http\Controllers\Controller;
use App\Http\Requests\AP\ApInvoicePostRequest;
use App\Http\Requests\AP\ApInvoiceStoreRequest;
use App\Http\Requests\AP\ApInvoiceUpdateRequest;
use App\Http\Requests\AP\ApInvoiceVoidRequest;
use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\ExpenseProfile;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\AP\ApInvoicePostingService;
use App\Services\AP\ApInvoiceTotalsService;
use App\Services\AP\ApInvoiceVoidService;
use App\Services\AP\SupplierAccountingPolicyService;
use App\Services\Spend\ExpenseWorkflowService;
use App\Support\AP\DocumentTypeMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        ApInvoiceTotalsService $totalsService,
        AccountingContextService $accountingContext,
        AccountingPeriodGateService $periodGate,
        SupplierAccountingPolicyService $supplierPolicy,
        ExpenseWorkflowService $expenseWorkflowService
    ): JsonResponse {
        $data = $request->validated();
        $document = DocumentTypeMap::derive((string) $data['document_type'], $data['expense_channel'] ?? null);
        $companyId = $accountingContext->resolveCompanyId($data['branch_id'] ?? null, $data['company_id'] ?? null);
        $periodId = $accountingContext->resolvePeriodId($data['invoice_date'] ?? null, $data['company_id'] ?? null);
        $periodGate->assertDateOpen((string) $data['invoice_date'], $companyId, $periodId, 'ap', 'invoice_date');
        $supplierPolicy->assertCanCreateDraft($request->supplier());

        $invoice = DB::transaction(function () use ($data, $totalsService, $document, $expenseWorkflowService, $companyId, $periodId) {
            $invoice = ApInvoice::create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'job_id' => $data['job_id'] ?? null,
                'period_id' => $periodId,
                'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'is_expense' => $document['is_expense'],
                'document_type' => $data['document_type'],
                'currency_code' => $data['currency_code'] ?? config('pos.currency', 'QAR'),
                'invoice_number' => $data['invoice_number'],
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'],
                'subtotal' => 0,
                'tax_amount' => $data['tax_amount'],
                'total_amount' => 0,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            foreach ($data['items'] as $item) {
                $lineTotal = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
                ApInvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'purchase_order_item_id' => $item['purchase_order_item_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                ]);
            }

            $totalsService->recalc($invoice);

            if ($document['is_expense']) {
                $expenseWorkflowService->initializeProfile(
                    $invoice,
                    (string) ($document['expense_channel'] ?? 'vendor'),
                    isset($data['wallet_id']) ? (int) $data['wallet_id'] : null
                );
            }

            return $invoice;
        });

        return response()->json($invoice->load(['items']), 201);
    }

    public function update(
        ApInvoiceUpdateRequest $request,
        ApInvoice $invoice,
        ApInvoiceTotalsService $totalsService,
        AccountingContextService $accountingContext,
        AccountingPeriodGateService $periodGate,
        SupplierAccountingPolicyService $supplierPolicy,
        ExpenseWorkflowService $expenseWorkflowService
    ): JsonResponse {
        $data = $request->validated();
        $document = DocumentTypeMap::derive((string) $data['document_type'], $data['expense_channel'] ?? null);

        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages(['status' => __('Only draft invoices can be edited.')]);
        }

        $companyId = $accountingContext->resolveCompanyId($data['branch_id'] ?? $invoice->branch_id, $data['company_id'] ?? $invoice->company_id);
        $periodId = $accountingContext->resolvePeriodId($data['invoice_date'] ?? null, $data['company_id'] ?? $invoice->company_id);
        $periodGate->assertDateOpen((string) $data['invoice_date'], $companyId, $periodId, 'ap', 'invoice_date');
        $supplierPolicy->assertCanCreateDraft($request->supplier());

        $invoice = DB::transaction(function () use ($invoice, $data, $totalsService, $document, $expenseWorkflowService, $companyId, $periodId) {
            $invoice->update([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? $invoice->branch_id,
                'department_id' => $data['department_id'] ?? $invoice->department_id,
                'job_id' => $data['job_id'] ?? $invoice->job_id,
                'period_id' => $periodId,
                'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'is_expense' => $document['is_expense'],
                'document_type' => $data['document_type'],
                'currency_code' => $data['currency_code'] ?? $invoice->currency_code ?? config('pos.currency', 'QAR'),
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
                    'purchase_order_item_id' => $item['purchase_order_item_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $lineTotal,
                ]);
            }

            $totalsService->recalc($invoice);

            if ($document['is_expense']) {
                $expenseWorkflowService->initializeProfile(
                    $invoice,
                    (string) ($document['expense_channel'] ?? 'vendor'),
                    isset($data['wallet_id']) ? (int) $data['wallet_id'] : null
                );
            } else {
                ExpenseProfile::query()->where('invoice_id', $invoice->id)->delete();
            }

            return $invoice;
        });

        return response()->json($invoice->load(['items']));
    }

    public function post(
        ApInvoicePostRequest $request,
        ApInvoice $invoice,
        ApInvoicePostingService $postingService
    ): JsonResponse {
        $invoice = $postingService->post(
            $invoice,
            Auth::id(),
            (bool) $request->boolean('matching_override'),
            $request->input('matching_override_reason')
        );

        return response()->json($invoice);
    }

    public function void(
        ApInvoiceVoidRequest $request,
        ApInvoice $invoice,
        ApInvoiceVoidService $voidService
    ): JsonResponse {
        $invoice = $voidService->void($invoice, Auth::id());

        return response()->json($invoice);
    }
}

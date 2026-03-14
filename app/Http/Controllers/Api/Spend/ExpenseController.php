<?php

namespace App\Http\Controllers\Api\Spend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Spend\SpendExpenseApproveRequest;
use App\Http\Requests\Spend\SpendExpenseRejectRequest;
use App\Http\Requests\Spend\SpendExpenseSettleRequest;
use App\Http\Requests\Spend\SpendExpenseStoreRequest;
use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\ExpenseProfile;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\AccountingPeriodGateService;
use App\Services\AP\ApInvoiceAttachmentService;
use App\Services\AP\ApInvoiceTotalsService;
use App\Services\Spend\ExpenseEventService;
use App\Services\Spend\ExpenseWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ApInvoice::query()
            ->where('is_expense', true)
            ->with(['supplier', 'category', 'expenseProfile.wallet'])
            ->withCount('expenseEvents')
            ->withSum('allocations as paid_sum', 'allocated_amount')
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('approval_status') && $request->approval_status !== 'all', fn ($q) => $q->whereHas('expenseProfile', fn ($sub) => $sub->where('approval_status', $request->input('approval_status'))))
            ->when($request->filled('channel') && $request->channel !== 'all', fn ($q) => $q->whereHas('expenseProfile', fn ($sub) => $sub->where('channel', $request->input('channel'))))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('invoice_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('invoice_date', '<=', $request->input('date_to')))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($sub) use ($request) {
                $search = (string) $request->input('search');
                $sub->where('invoice_number', 'like', '%'.$search.'%')
                    ->orWhere('notes', 'like', '%'.$search.'%');
            }))
            ->orderByDesc('invoice_date');

        $page = $query->paginate($request->integer('per_page', 15));
        $page->setCollection($page->getCollection()->map(fn (ApInvoice $invoice) => $this->presentInvoice($invoice)));

        return response()->json($page);
    }

    public function show(ApInvoice $invoice): JsonResponse
    {
        $this->assertExpenseInvoice($invoice);

        $invoice->load(['supplier', 'category', 'items', 'allocations.payment', 'attachments', 'expenseProfile.wallet'])
            ->loadCount('expenseEvents')
            ->loadSum('allocations as paid_sum', 'allocated_amount');

        return response()->json($this->presentInvoice($invoice));
    }

    public function store(
        SpendExpenseStoreRequest $request,
        ApInvoiceTotalsService $totalsService,
        ExpenseWorkflowService $workflowService,
        ExpenseEventService $eventService,
        AccountingContextService $accountingContext,
        AccountingPeriodGateService $periodGate
    ): JsonResponse {
        $data = $request->validated();
        $userId = (int) Auth::id();

        $supplierId = $this->resolveSupplierId($data);
        $channel = (string) ($data['channel'] ?? 'vendor');
        $walletId = isset($data['wallet_id']) ? (int) $data['wallet_id'] : null;
        $companyId = $accountingContext->resolveCompanyId($data['branch_id'] ?? null, $data['company_id'] ?? null);
        $periodId = $accountingContext->resolvePeriodId($data['expense_date'] ?? null, $data['company_id'] ?? null);
        $periodGate->assertDateOpen((string) $data['expense_date'], $companyId, $periodId, 'ap', 'expense_date');

        $invoice = DB::transaction(function () use ($data, $supplierId, $totalsService, $userId, $workflowService, $eventService, $channel, $walletId, $companyId, $periodId) {
            $invoice = ApInvoice::create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'job_id' => $data['job_id'] ?? null,
                'period_id' => $periodId,
                'supplier_id' => $supplierId,
                'purchase_order_id' => null,
                'category_id' => $data['category_id'],
                'is_expense' => true,
                'document_type' => $channel === 'reimbursement' ? 'reimbursement' : 'expense',
                'currency_code' => $data['currency_code'] ?? config('pos.currency', 'QAR'),
                'invoice_number' => $this->generateExpenseInvoiceNumber($supplierId),
                'invoice_date' => $data['expense_date'],
                'due_date' => $data['due_date'] ?? $data['expense_date'],
                'subtotal' => 0,
                'tax_amount' => $data['tax_amount'],
                'total_amount' => 0,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $lineTotal = round((float) $data['amount'], 2);
            ApInvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $data['description'],
                'quantity' => 1,
                'unit_price' => $lineTotal,
                'line_total' => $lineTotal,
            ]);

            $totalsService->recalc($invoice);
            $workflowService->initializeProfile($invoice, $channel, $walletId);
            $eventService->log($invoice, 'created', $userId, ['channel' => $channel]);

            return $invoice;
        });

        $invoice = ApInvoice::query()
            ->with(['supplier', 'category', 'items', 'allocations.payment', 'attachments', 'expenseProfile.wallet'])
            ->withCount('expenseEvents')
            ->withSum('allocations as paid_sum', 'allocated_amount')
            ->findOrFail($invoice->id);

        return response()->json($this->presentInvoice($invoice), Response::HTTP_CREATED);
    }

    public function submit(ApInvoice $invoice, ExpenseWorkflowService $workflowService): JsonResponse
    {
        $this->assertExpenseInvoice($invoice);

        $invoice = $workflowService->submit($invoice, (int) Auth::id())
            ->load(['supplier', 'category', 'items', 'allocations.payment', 'attachments', 'expenseProfile.wallet'])
            ->loadCount('expenseEvents')
            ->loadSum('allocations as paid_sum', 'allocated_amount');

        return response()->json($this->presentInvoice($invoice));
    }

    public function approve(SpendExpenseApproveRequest $request, ApInvoice $invoice, ExpenseWorkflowService $workflowService): JsonResponse
    {
        $this->assertExpenseInvoice($invoice);

        $stage = (string) $request->validated('stage');
        $user = $request->user();

        if ($stage === 'manager' && ! $this->canManagerApprove($user)) {
            abort(403);
        }

        if ($stage === 'finance' && ! $this->canFinanceApprove($user)) {
            abort(403);
        }

        $invoice = $workflowService->approve($invoice, (int) Auth::id(), $stage)
            ->load(['supplier', 'category', 'items', 'allocations.payment', 'attachments', 'expenseProfile.wallet'])
            ->loadCount('expenseEvents')
            ->loadSum('allocations as paid_sum', 'allocated_amount');

        return response()->json($this->presentInvoice($invoice));
    }

    public function reject(SpendExpenseRejectRequest $request, ApInvoice $invoice, ExpenseWorkflowService $workflowService): JsonResponse
    {
        $this->assertExpenseInvoice($invoice);

        if (! $this->canManagerApprove($request->user()) && ! $this->canFinanceApprove($request->user())) {
            abort(403);
        }

        $invoice = $workflowService->reject($invoice, (int) Auth::id(), (string) $request->validated('reason'))
            ->load(['supplier', 'category', 'items', 'allocations.payment', 'attachments', 'expenseProfile.wallet'])
            ->loadCount('expenseEvents')
            ->loadSum('allocations as paid_sum', 'allocated_amount');

        return response()->json($this->presentInvoice($invoice));
    }

    public function post(ApInvoice $invoice, ExpenseWorkflowService $workflowService): JsonResponse
    {
        $this->assertExpenseInvoice($invoice);

        if (! $this->canFinanceApprove(request()->user())) {
            abort(403);
        }

        $invoice = $workflowService->post($invoice, (int) Auth::id())
            ->load(['supplier', 'category', 'items', 'allocations.payment', 'attachments', 'expenseProfile.wallet'])
            ->loadCount('expenseEvents')
            ->loadSum('allocations as paid_sum', 'allocated_amount');

        return response()->json($this->presentInvoice($invoice));
    }

    public function settle(SpendExpenseSettleRequest $request, ApInvoice $invoice, ExpenseWorkflowService $workflowService): JsonResponse
    {
        $this->assertExpenseInvoice($invoice);

        if (! $this->canFinanceApprove($request->user())) {
            abort(403);
        }

        $invoice = $workflowService->settle($invoice, (int) Auth::id(), $request->validated())
            ->load(['supplier', 'category', 'items', 'allocations.payment', 'attachments', 'expenseProfile.wallet'])
            ->loadCount('expenseEvents')
            ->loadSum('allocations as paid_sum', 'allocated_amount');

        return response()->json($this->presentInvoice($invoice));
    }

    public function storeAttachment(
        Request $request,
        ApInvoice $invoice,
        ApInvoiceAttachmentService $attachmentService
    ): JsonResponse {
        $this->assertExpenseInvoice($invoice);

        $request->validate([
            'file' => ['required', 'file'],
        ]);

        $attachment = $attachmentService->upload($invoice, $request->file('file'), Auth::id());

        return response()->json($attachment, Response::HTTP_CREATED);
    }

    private function assertExpenseInvoice(ApInvoice $invoice): void
    {
        if (! $invoice->is_expense) {
            abort(404);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveSupplierId(array $data): int
    {
        $supplierId = isset($data['supplier_id']) ? (int) $data['supplier_id'] : 0;
        if ($supplierId > 0) {
            return $supplierId;
        }

        $fallback = (int) config('spend.petty_cash_internal_supplier_id', 0);
        if ($fallback > 0) {
            return $fallback;
        }

        throw ValidationException::withMessages([
            'supplier_id' => __('Supplier is required for this expense.'),
        ]);
    }

    private function generateExpenseInvoiceNumber(int $supplierId): string
    {
        $prefix = 'EXP-'.now()->format('Ymd');

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = $prefix.'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $exists = ApInvoice::query()
                ->where('supplier_id', $supplierId)
                ->where('invoice_number', $candidate)
                ->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        return $prefix.'-'.strtoupper(Str::random(6));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentInvoice(ApInvoice $invoice): array
    {
        $paid = (float) ($invoice->paid_sum ?? $invoice->allocations()->sum('allocated_amount'));
        $outstanding = max((float) $invoice->total_amount - $paid, 0);

        /** @var ExpenseProfile|null $profile */
        $profile = $invoice->relationLoaded('expenseProfile') ? $invoice->expenseProfile : null;

        $approvalStatus = (string) ($profile?->approval_status ?? 'draft');
        $requiresFinance = (bool) ($profile?->requires_finance_approval ?? false);
        $exceptionFlags = (array) ($profile?->exception_flags ?? []);

        return [
            'id' => $invoice->id,
            'supplier_id' => $invoice->supplier_id,
            'category_id' => $invoice->category_id,
            'invoice_number' => $invoice->invoice_number,
            'expense_date' => optional($invoice->invoice_date)->toDateString(),
            'due_date' => optional($invoice->due_date)->toDateString(),
            'subtotal' => (float) $invoice->subtotal,
            'tax_amount' => (float) $invoice->tax_amount,
            'total_amount' => (float) $invoice->total_amount,
            'paid_amount' => round($paid, 2),
            'outstanding_amount' => round($outstanding, 2),
            'status' => $invoice->status,
            'notes' => $invoice->notes,
            'supplier' => $invoice->supplier,
            'category' => $invoice->category,
            'items' => $invoice->relationLoaded('items') ? $invoice->items : [],
            'allocations' => $invoice->relationLoaded('allocations') ? $invoice->allocations : [],
            'attachments' => $invoice->relationLoaded('attachments') ? $invoice->attachments : [],
            'channel' => $profile?->channel,
            'wallet_id' => $profile?->wallet_id,
            'approval_status' => $approvalStatus,
            'requires_finance_approval' => $requiresFinance,
            'exception_flags' => $exceptionFlags,
            'workflow_state' => app(ExpenseWorkflowService::class)->workflowState($invoice),
            'audit_events_count' => (int) ($invoice->expense_events_count ?? $invoice->expenseEvents()->count()),
        ];
    }

    private function canManagerApprove($user): bool
    {
        return (bool) ($user?->hasAnyRole(['admin', 'manager']));
    }

    private function canFinanceApprove($user): bool
    {
        return (bool) ($user?->hasRole('admin') || $user?->can('finance.access'));
    }
}

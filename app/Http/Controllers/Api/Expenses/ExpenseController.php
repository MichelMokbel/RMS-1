<?php

namespace App\Http\Controllers\Api\Expenses;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\ExpensePaymentStoreRequest;
use App\Http\Requests\Expenses\ExpenseStoreRequest;
use App\Http\Requests\Expenses\ExpenseUpdateRequest;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Services\Expenses\ExpenseAttachmentService;
use App\Services\Expenses\ExpensePaymentService;
use App\Services\Expenses\ExpensePaymentStatusService;
use App\Services\Expenses\ExpenseTotalsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Expense::query()
            ->with(['supplier', 'category'])
            ->withSum('payments as paid_sum', 'amount')
            ->when($request->supplier_id, fn ($q) => $q->where('supplier_id', $request->supplier_id))
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->payment_status, fn ($q) => $q->where('payment_status', $request->payment_status))
            ->when($request->payment_method, fn ($q) => $q->where('payment_method', $request->payment_method))
            ->when($request->date_from, fn ($q) => $q->whereDate('expense_date', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('expense_date', '<=', $request->date_to))
            ->when($request->search, fn ($q) => $q->where(function ($sub) use ($request) {
                $sub->where('description', 'like', '%'.$request->search.'%')
                    ->orWhere('reference', 'like', '%'.$request->search.'%');
            }))
            ->orderByDesc('expense_date');

        return $query->paginate($request->get('per_page', 15));
    }

    public function show(Expense $expense)
    {
        return $expense->load(['supplier', 'category', 'payments', 'attachments']);
    }

    public function store(ExpenseStoreRequest $request, ExpenseTotalsService $totalsService, ExpensePaymentService $paymentService)
    {
        $data = $request->validated();
        $expense = DB::transaction(function () use ($data, $totalsService, $paymentService) {
            $expense = Expense::create([
                'category_id' => $data['category_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'expense_date' => $data['expense_date'],
                'description' => $data['description'],
                'amount' => $data['amount'],
                'tax_amount' => $data['tax_amount'],
                'total_amount' => 0,
                'payment_status' => $data['payment_status'],
                'payment_method' => $data['payment_method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id ?? null,
            ]);

            $totalsService->recalc($expense);

            if ($request->boolean('record_payment', false) && $expense->total_amount > 0) {
                $paymentService->addPayment($expense, [
                    'payment_date' => $expense->expense_date,
                    'amount' => $expense->total_amount,
                    'payment_method' => $expense->payment_method,
                    'reference' => $expense->reference,
                    'notes' => $expense->notes,
                ], $request->user()->id ?? null);
            }

            return $expense->fresh();
        });

        return response()->json($expense, Response::HTTP_CREATED);
    }

    public function update(ExpenseUpdateRequest $request, Expense $expense, ExpenseTotalsService $totalsService, ExpensePaymentStatusService $statusService)
    {
        $data = $request->validated();
        $hasPayments = $expense->payments()->exists();

        $expense = DB::transaction(function () use ($expense, $data, $hasPayments, $totalsService, $statusService) {
            $expense->update([
                'category_id' => $data['category_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'expense_date' => $data['expense_date'],
                'description' => $data['description'],
                'payment_status' => $data['payment_status'],
                'payment_method' => $data['payment_method'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ] + ($hasPayments ? [] : [
                'amount' => $data['amount'] ?? $expense->amount,
                'tax_amount' => $data['tax_amount'] ?? $expense->tax_amount,
            ]));

            if (! $hasPayments) {
                $totalsService->recalc($expense);
            }
            $statusService->recalc($expense->fresh());

            return $expense->fresh();
        });

        return $expense;
    }

    public function destroy(Expense $expense)
    {
        if ($expense->payments()->exists() || $expense->attachments()->exists()) {
            throw ValidationException::withMessages(['expense' => __('Cannot delete expense with payments or attachments.')]);
        }
        $expense->delete();
        return response()->noContent();
    }

    public function storePayment(ExpensePaymentStoreRequest $request, Expense $expense, ExpensePaymentService $paymentService)
    {
        $payment = $paymentService->addPayment($expense, $request->validated(), $request->user()->id ?? null);
        return response()->json($payment, Response::HTTP_CREATED);
    }

    public function storeAttachment(Request $request, Expense $expense, ExpenseAttachmentService $attachmentService)
    {
        $request->validate([
            'file' => ['required', 'file'],
        ]);
        $attachment = $attachmentService->upload($expense, $request->file('file'), $request->user()->id ?? null);
        return response()->json($attachment, Response::HTTP_CREATED);
    }

    public function destroyAttachment(ExpenseAttachment $attachment, ExpenseAttachmentService $attachmentService)
    {
        $attachmentService->delete($attachment);
        return response()->noContent();
    }
}

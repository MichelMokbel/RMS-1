<?php

namespace App\Http\Controllers\Api\PettyCash;

use App\Http\Controllers\Controller;
use App\Models\PettyCashExpense;
use App\Models\PettyCashWallet;
use App\Services\PettyCash\PettyCashExpenseWorkflowService;
use App\Services\PettyCash\PettyCashReceiptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $expenses = PettyCashExpense::with(['wallet', 'category', 'submitter', 'approver'])
            ->when($request->filled('wallet_id'), fn ($q) => $q->where('wallet_id', $request->integer('wallet_id')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('expense_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('expense_date', '<=', $request->input('to')))
            ->orderByDesc('expense_date')
            ->get();

        return response()->json($expenses);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'wallet_id' => ['required', 'integer', 'exists:petty_cash_wallets,id'],
            'category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'expense_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'tax_amount' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:draft,submitted'],
        ]);

        $wallet = PettyCashWallet::findOrFail($data['wallet_id']);
        if (! $wallet->isActive()) {
            throw ValidationException::withMessages(['wallet_id' => __('Wallet is inactive.')]);
        }

        $expense = PettyCashExpense::create([
            'wallet_id' => $data['wallet_id'],
            'category_id' => $data['category_id'],
            'expense_date' => $data['expense_date'],
            'description' => $data['description'],
            'amount' => $data['amount'],
            'tax_amount' => $data['tax_amount'],
            'total_amount' => round($data['amount'] + $data['tax_amount'], 2),
            'status' => $data['status'] ?? 'submitted',
            'submitted_by' => $request->user()->id,
        ]);

        return response()->json($expense->fresh(['wallet', 'category']), 201);
    }

    public function update(int $id, Request $request, PettyCashExpenseWorkflowService $workflow, PettyCashReceiptService $receiptService)
    {
        $expense = PettyCashExpense::findOrFail($id);
        $action = $request->input('action');

        if ($action === 'submit') {
            $workflow->submit($expense, $request->user()->id);
        } elseif ($action === 'approve') {
            $this->authorizeManager($request);
            $workflow->approve($expense, $request->user()->id);
        } elseif ($action === 'reject') {
            $this->authorizeManager($request);
            $workflow->reject($expense, $request->user()->id, $request->input('reason'));
        } elseif ($request->hasFile('receipt')) {
            $receiptService->upload($expense, $request->file('receipt'));
        } else {
            // Basic edits if still editable
            if (! $expense->isEditable()) {
                throw ValidationException::withMessages(['status' => __('Expense not editable')]);
            }

            $data = $request->validate([
                'description' => ['sometimes', 'string', 'max:255'],
                'amount' => ['sometimes', 'numeric', 'min:0'],
                'tax_amount' => ['sometimes', 'numeric', 'min:0'],
            ]);

            $expense->fill($data);
            $expense->recalcTotals();
        }

        return response()->json($expense->fresh(['wallet', 'category', 'submitter', 'approver']));
    }

    private function authorizeManager(Request $request): void
    {
        $user = $request->user();
        if (! $user || ! $user->hasAnyRole(['admin', 'manager'])) {
            abort(403);
        }
    }
}

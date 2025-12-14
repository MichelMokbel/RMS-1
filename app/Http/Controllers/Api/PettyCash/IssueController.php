<?php

namespace App\Http\Controllers\Api\PettyCash;

use App\Http\Controllers\Controller;
use App\Models\PettyCashIssue;
use App\Services\PettyCash\PettyCashIssueService;
use Illuminate\Http\Request;

class IssueController extends Controller
{
    public function index(Request $request)
    {
        $issues = PettyCashIssue::with('wallet')
            ->when($request->filled('wallet_id'), fn ($q) => $q->where('wallet_id', $request->integer('wallet_id')))
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->input('method')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('issue_date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('issue_date', '<=', $request->input('to')))
            ->orderByDesc('issue_date')
            ->get();

        return response()->json($issues);
    }

    public function store(Request $request, PettyCashIssueService $service)
    {
        $data = $request->validate([
            'wallet_id' => ['required', 'integer', 'exists:petty_cash_wallets,id'],
            'issue_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,card,bank_transfer,cheque,other'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $issue = $service->createIssue($data['wallet_id'], $data, $request->user()->id);

        return response()->json($issue, 201);
    }
}

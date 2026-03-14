<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\BankReconciliationStoreRequest;
use App\Http\Requests\Accounting\BankStatementImportStoreRequest;
use App\Models\BankAccount;
use App\Models\BankReconciliationRun;
use App\Models\BankStatementImport;
use App\Models\BankTransaction;
use App\Services\Banking\BankReconciliationService;
use App\Services\Banking\BankStatementImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $bankAccountId = $request->integer('bank_account_id');

        $accounts = BankAccount::query()
            ->orderBy('name')
            ->get();

        $transactions = BankTransaction::query()
            ->with(['bankAccount', 'statementImport'])
            ->when($bankAccountId > 0, fn ($query) => $query->where('bank_account_id', $bankAccountId))
            ->latest('transaction_date')
            ->limit(100)
            ->get();

        $imports = BankStatementImport::query()
            ->with('bankAccount')
            ->when($bankAccountId > 0, fn ($query) => $query->where('bank_account_id', $bankAccountId))
            ->latest('processed_at')
            ->limit(20)
            ->get();

        $reconciliations = BankReconciliationRun::query()
            ->with(['bankAccount', 'statementImport'])
            ->when($bankAccountId > 0, fn ($query) => $query->where('bank_account_id', $bankAccountId))
            ->latest('statement_date')
            ->limit(20)
            ->get();

        return response()->json([
            'accounts' => $accounts,
            'transactions' => $transactions,
            'imports' => $imports,
            'reconciliations' => $reconciliations,
        ]);
    }

    public function storeImport(BankStatementImportStoreRequest $request, BankStatementImportService $service): JsonResponse
    {
        $bankAccount = BankAccount::query()->findOrFail($request->integer('bank_account_id'));
        $result = $service->import($bankAccount, $request->file('statement_file'), (int) $request->user()->id);

        return response()->json($result, 201);
    }

    public function reconcile(BankReconciliationStoreRequest $request, BankReconciliationService $service): JsonResponse
    {
        $bankAccount = BankAccount::query()->findOrFail($request->integer('bank_account_id'));
        $result = $service->reconcile($bankAccount, $request->validated(), (int) $request->user()->id);

        return response()->json($result, 201);
    }
}

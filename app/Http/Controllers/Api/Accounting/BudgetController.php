<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\BudgetVersionStoreRequest;
use App\Models\BudgetVersion;
use App\Services\Accounting\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->integer('company_id');

        $budgets = BudgetVersion::query()
            ->with(['company', 'fiscalYear'])
            ->withCount('lines')
            ->when($companyId > 0, fn ($query) => $query->where('company_id', $companyId))
            ->latest('created_at')
            ->get();

        return response()->json(['budgets' => $budgets]);
    }

    public function store(BudgetVersionStoreRequest $request, BudgetService $service): JsonResponse
    {
        $budget = $service->createVersion($request->validated(), (int) $request->user()->id);

        return response()->json([
            'budget' => $budget,
            'variance' => $service->variance($budget),
        ], 201);
    }

    public function variance(BudgetVersion $budgetVersion, BudgetService $service): JsonResponse
    {
        return response()->json($service->variance($budgetVersion->load('fiscalYear')));
    }
}

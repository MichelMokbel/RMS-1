<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\JobStoreRequest;
use App\Http\Requests\Accounting\JobTransactionStoreRequest;
use App\Models\Job;
use App\Services\Accounting\JobCostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->integer('company_id');

        $jobs = Job::query()
            ->withCount(['phases', 'transactions'])
            ->when($companyId > 0, fn ($query) => $query->where('company_id', $companyId))
            ->latest('created_at')
            ->get();

        return response()->json(['jobs' => $jobs]);
    }

    public function store(JobStoreRequest $request, JobCostingService $service): JsonResponse
    {
        $job = $service->createJob($request->validated(), (int) $request->user()->id);

        return response()->json([
            'job' => $job,
            'profitability' => $service->profitability($job),
        ], 201);
    }

    public function storeTransaction(JobTransactionStoreRequest $request, Job $job, JobCostingService $service): JsonResponse
    {
        $transaction = $service->recordTransaction($job->load('phases', 'budgets'), $request->validated(), (int) $request->user()->id);

        return response()->json([
            'transaction' => $transaction,
            'profitability' => $service->profitability($job->fresh(['phases', 'budgets'])),
        ], 201);
    }

    public function profitability(Job $job, JobCostingService $service): JsonResponse
    {
        return response()->json($service->profitability($job->load(['phases', 'budgets'])));
    }
}

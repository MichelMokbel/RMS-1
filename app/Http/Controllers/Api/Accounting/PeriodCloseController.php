<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ClosingChecklistUpdateRequest;
use App\Http\Requests\Accounting\PeriodCloseRequest;
use App\Http\Requests\Accounting\PeriodReopenRequest;
use App\Models\AccountingPeriod;
use App\Models\ClosingChecklist;
use App\Services\Accounting\AccountingPeriodChecklistService;
use App\Services\Accounting\AccountingPeriodCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PeriodCloseController extends Controller
{
    public function index(Request $request, AccountingPeriodCloseService $service): JsonResponse
    {
        $companyId = $request->integer('company_id') ?: null;
        $periods = $service->companyPeriods($companyId);

        return response()->json([
            'periods' => $periods,
        ]);
    }

    public function show(AccountingPeriod $period, AccountingPeriodCloseService $service): JsonResponse
    {
        return response()->json($service->readiness($period));
    }

    public function refresh(AccountingPeriod $period, AccountingPeriodCloseService $service): JsonResponse
    {
        return response()->json($service->readiness($period));
    }

    public function completeChecklist(
        ClosingChecklistUpdateRequest $request,
        ClosingChecklist $checklist,
        AccountingPeriodChecklistService $service
    ): JsonResponse {
        $item = $service->completeManualTask($checklist, (int) $request->user()->id, $request->string('notes')->toString() ?: null);

        return response()->json($item);
    }

    public function resetChecklist(
        ClosingChecklistUpdateRequest $request,
        ClosingChecklist $checklist,
        AccountingPeriodChecklistService $service
    ): JsonResponse {
        $item = $service->resetManualTask($checklist, (int) $request->user()->id, $request->string('notes')->toString() ?: null);

        return response()->json($item);
    }

    public function close(
        PeriodCloseRequest $request,
        AccountingPeriod $period,
        AccountingPeriodCloseService $service
    ): JsonResponse {
        $period = $service->close($period, (int) $request->user()->id, (string) $request->validated('close_note'));

        return response()->json($service->readiness($period));
    }

    public function reopen(
        PeriodReopenRequest $request,
        AccountingPeriod $period,
        AccountingPeriodCloseService $service
    ): JsonResponse {
        $period = $service->reopen(
            $period,
            (int) $request->user()->id,
            (string) $request->validated('reopen_reason'),
            (bool) $request->boolean('move_lock_date_back')
        );

        return response()->json($service->readiness($period));
    }
}

<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function summary(Request $request, AccountingReportService $service): JsonResponse
    {
        return response()->json(
            $service->summary(
                $request->integer('company_id') ?: null,
                $request->string('date_to')->toString() ?: null,
            )
        );
    }
}

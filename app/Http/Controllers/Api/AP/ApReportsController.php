<?php

namespace App\Http\Controllers\Api\AP;

use App\Http\Controllers\Controller;
use App\Services\AP\ApReportsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApReportsController extends Controller
{
    public function __construct(protected ApReportsService $reportsService)
    {
    }

    public function aging(Request $request): JsonResponse
    {
        $summary = $this->reportsService->agingSummary($request->integer('supplier_id'));
        return response()->json($summary);
    }
}

<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\PrintMealPlanRequestsRequest;
use App\Models\Customer;
use App\Models\MealPlanRequest;
use App\Models\Order;
use App\Services\Reports\MealPlanRequestReportService;

class MealPlanRequestReportController extends Controller
{
    public function printSelected(PrintMealPlanRequestsRequest $request, MealPlanRequestReportService $service)
    {
        $data = $request->validated();

        return view('reports.meal-plan-request-print', $service->combined(
            $request->user(),
            Customer::findOrFail($data['customer_id']),
            $data['request_ids'],
        ));
    }

    public function print(MealPlanRequest $mealPlanRequest)
    {
        $orderIds = $mealPlanRequest->linkedOrderIds();
        $orders = empty($orderIds)
            ? collect()
            : Order::query()
                ->with(['items' => fn ($query) => $query->with('menuItem')->orderBy('sort_order')->orderBy('id')])
                ->whereIn('id', $orderIds)
                ->orderBy('scheduled_date')
                ->orderBy('id')
                ->get();

        $days = $orders->groupBy(function (Order $order): string {
            return $order->scheduled_date?->format('Y-m-d') ?? 'unscheduled';
        })->map(function ($group, string $date) {
            return [
                'date' => $date,
                'orders' => $group,
                'day_total' => round((float) $group->sum(fn (Order $order) => (float) $order->total_amount), 3),
            ];
        })->values();

        $grandTotal = round((float) $orders->sum(fn (Order $order) => (float) $order->total_amount), 3);

        return view('reports.meal-plan-request-print', [
            'mealPlanRequest' => $mealPlanRequest,
            'days' => $days,
            'grandTotal' => $grandTotal,
            'generatedAt' => now(),
        ]);
    }
}

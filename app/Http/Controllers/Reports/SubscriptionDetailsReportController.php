<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\MealSubscription;
use App\Support\Reports\CsvExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubscriptionDetailsReportController extends Controller
{
    private function query(Request $request, int $limit = 500)
    {
        return MealSubscription::query()
            ->with(['customer'])
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('start_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->where(function ($qq) use ($request) {
                $qq->whereDate('end_date', '<=', $request->date_to)
                   ->orWhereNull('end_date');
            }))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->search.'%';
                $q->where(fn ($qq) => $qq->where('subscription_code', 'like', $term)
                    ->orWhereHas('customer', fn ($qc) => $qc->where('name', 'like', $term)));
            })
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function print(Request $request)
    {
        $subscriptions = $this->query($request);
        $filters = $request->only(['status', 'customer_id', 'branch_id', 'date_from', 'date_to', 'search']);

        return view('reports.subscription-details-print', [
            'subscriptions' => $subscriptions,
            'filters' => $filters,
            'generatedAt' => now(),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $subscriptions = $this->query($request, 2000);
        $headers = [
            __('Subscription Code'),
            __('Customer'),
            __('Status'),
            __('Start Date'),
            __('End Date'),
            __('Order Type'),
            __('Plan'),
            __('Meals Used'),
            __('Created'),
        ];
        $rows = $subscriptions->map(fn ($s) => [
            $s->subscription_code,
            $s->customer->name ?? '',
            ucfirst($s->status),
            $s->start_date?->format('Y-m-d') ?? '',
            $s->end_date?->format('Y-m-d') ?? '',
            $s->default_order_type,
            $s->plan_meals_total ? $s->plan_meals_total . ' meals' : 'Unlimited',
            $s->plan_meals_total ? ($s->meals_used ?? 0) . ' / ' . $s->plan_meals_total : ($s->meals_used ?? 0),
            $s->created_at?->format('Y-m-d') ?? '',
        ]);

        return CsvExport::stream($headers, $rows, 'subscription-details-report.csv');
    }
}

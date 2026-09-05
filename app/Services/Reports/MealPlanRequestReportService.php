<?php

namespace App\Services\Reports;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\MealPlanRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\Security\BranchAccessService;
use App\Support\Money\MinorUnits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class MealPlanRequestReportService
{
    public function __construct(private readonly BranchAccessService $branchAccess) {}

    public function requestsForCustomer(User $actor, int $customerId): Builder
    {
        abort_unless($actor->isActive() && ($actor->hasAnyRole(['admin', 'manager']) || $actor->can('operations.access')), 403);

        $query = MealPlanRequest::query()->where(function (Builder $query) use ($customerId) {
            $query->where('customer_id', $customerId)
                ->orWhere(function (Builder $legacy) use ($customerId) {
                    // Older converted requests may only have a persisted customer link through their subscription or orders.
                    $legacy->whereNull('customer_id')->where(function (Builder $links) use ($customerId) {
                        $links->whereExists(fn ($subscription) => $subscription->selectRaw('1')->from('meal_subscriptions')
                            ->whereColumn('meal_subscriptions.meal_plan_request_id', 'meal_plan_requests.id')
                            ->where('meal_subscriptions.customer_id', $customerId))
                            ->orWhereHas('orders', fn (Builder $orders) => $orders->where('customer_id', $customerId));
                    });
                });
        })->whereDoesntHave('orders', fn (Builder $orders) => $orders->whereNotNull('customer_id')->where('customer_id', '<>', $customerId));

        if (! $actor->isAdmin()) {
            $allowed = $this->branchAccess->allowedBranchIds($actor);
            // Reject entire requests rather than silently omitting some of their orders.
            $query->whereDoesntHave('orders', fn (Builder $orders) => $orders->where(function (Builder $branches) use ($allowed) {
                $branches->whereNull('branch_id')->orWhereNotIn('branch_id', $allowed);
            }));
        }

        return $query;
    }

    public function combined(User $actor, Customer $customer, array $requestIds): array
    {
        $requests = $this->requestsForCustomer($actor, (int) $customer->id)
            ->whereKey($requestIds)->orderBy('created_at')->orderBy('id')->get();
        abort_unless($requests->isNotEmpty() && $requests->count() === count(array_unique($requestIds)), 403);

        $links = DB::table('meal_plan_request_orders')->whereIn('meal_plan_request_id', $requests->modelKeys())->get();
        $orders = Order::query()->with(['items' => fn ($query) => $query->with('menuItem')->orderBy('sort_order')->orderBy('id')])
            ->whereIn('id', $links->pluck('order_id')->unique())
            ->orderBy('scheduled_date')->orderBy('id')->get();
        $totalMinor = fn ($group) => $group->sum(fn (Order $order) => MinorUnits::parse((string) $order->total_amount, 1000));
        $days = $orders->groupBy(fn (Order $order) => $order->scheduled_date?->format('Y-m-d') ?? 'unscheduled')
            ->map(fn ($group, string $date) => [
                'date' => $date,
                'orders' => $group,
                'day_total' => MinorUnits::format($totalMinor($group), 1000),
            ])->values();
        $dates = $days->pluck('date')->reject(fn ($date) => $date === 'unscheduled');

        return [
            'isCombined' => true,
            'mealPlanRequest' => $requests->first(),
            'mealPlanRequests' => $requests,
            'reportCustomer' => $customer,
            'orderRequestIds' => $links->groupBy('order_id')->map(fn ($group) => $group->pluck('meal_plan_request_id')->unique()->sort()->values()),
            'days' => $days,
            'grandTotal' => MinorUnits::format($totalMinor($orders), 1000),
            'generatedAt' => now(),
            'startAt' => $dates->isNotEmpty() ? $dates->min().' 00:00:00' : null,
            'endAt' => $dates->isNotEmpty() ? $dates->max().' 23:59:59' : null,
            'warehouse' => Branch::query()->whereIn('id', $orders->pluck('branch_id')->unique())->orderBy('name')->pluck('name')->join(', ') ?: '—',
        ];
    }
}

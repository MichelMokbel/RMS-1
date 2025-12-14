<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MealSubscription;
use App\Services\Subscriptions\MealSubscriptionService;
use Illuminate\Http\Request;

class MealSubscriptionController extends Controller
{
    public function index(Request $request)
    {
        $query = MealSubscription::query()
            ->with(['days', 'pauses'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->orderByDesc('created_at');

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function show(MealSubscription $subscription)
    {
        return response()->json($subscription->load(['days', 'pauses', 'customer']));
    }

    public function store(Request $request, MealSubscriptionService $service)
    {
        $data = $this->validatePayload($request);
        $sub = $service->save($data, null, $request->user()->id);

        return response()->json($sub->load(['days', 'pauses']), 201);
    }

    public function update(MealSubscription $subscription, Request $request, MealSubscriptionService $service)
    {
        $data = $this->validatePayload($request, $subscription->id);
        $sub = $service->save($data, $subscription, $request->user()->id);

        return response()->json($sub->load(['days', 'pauses']));
    }

    public function pause(MealSubscription $subscription, Request $request, MealSubscriptionService $service)
    {
        $data = $request->validate([
            'pause_start' => ['required', 'date'],
            'pause_end' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $sub = $service->pause($subscription, $data, $request->user()->id);

        return response()->json($sub);
    }

    public function resume(MealSubscription $subscription, MealSubscriptionService $service)
    {
        $sub = $service->resume($subscription);
        return response()->json($sub);
    }

    public function cancel(MealSubscription $subscription, MealSubscriptionService $service)
    {
        $sub = $service->cancel($subscription);
        return response()->json($sub);
    }

    private function validatePayload(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'customer_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer'],
            'status' => ['required', 'in:active,paused,cancelled,expired'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date'],
            'default_order_type' => ['required', 'in:Delivery,Takeaway'],
            'delivery_time' => ['nullable', 'date_format:H:i'],
            'address_snapshot' => ['nullable', 'string'],
            'phone_snapshot' => ['nullable', 'string', 'max:50'],
            'preferred_role' => ['required', 'in:main,diet,vegetarian'],
            'include_salad' => ['boolean'],
            'include_dessert' => ['boolean'],
            'notes' => ['nullable', 'string'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'min:1', 'max:7'],
        ]);
    }
}


<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Orders\CustomerDailyDishOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class PublicDailyDishOrderController extends Controller
{
    public function __construct(
        private readonly CustomerDailyDishOrderService $service,
    ) {
    }

    public function store(Request $request)
    {
        $branchRule = ['nullable', 'integer'];
        if (Schema::hasTable('branches')) {
            $branchRule[] = Rule::exists('branches', 'id');
        }

        $payload = $request->validate([
            'branch_id' => $branchRule,
            'customerName' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
            'mealPlan' => ['nullable', 'in:20,26'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.key' => ['required', 'date'],
            'items.*.mains' => ['nullable', 'array'],
            'items.*.mains.*.name' => ['required_with:items.*.mains', 'string'],
            'items.*.mains.*.portion' => ['required_with:items.*.mains', 'in:plate,half,full'],
            'items.*.mains.*.qty' => ['required_with:items.*.mains', 'integer', 'min:1'],
            'items.*.salad' => ['nullable', 'string'],
            'items.*.dessert' => ['nullable', 'string'],
            'items.*.salad_qty' => ['nullable', 'integer', 'min:0'],
            'items.*.dessert_qty' => ['nullable', 'integer', 'min:0'],
            'items.*.mealType' => ['nullable', 'string'],
            'items.*.main' => ['nullable', 'string'],
            'items.*.menu_item_id' => ['nullable', 'integer'],
            'items.*.day_total' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($this->service->create($request->user(), $payload));
    }
}

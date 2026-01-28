<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerStoreRequest;
use App\Http\Requests\CustomerUpdateRequest;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $light = $request->boolean('light', true);
        $search = $request->input('search');
        $type = $request->input('customer_type');
        $active = $request->has('active') ? $request->boolean('active') : true;
        $perPage = (int) $request->input('per_page', 20);

        $query = Customer::query()
            ->when($active !== null, fn ($q) => $active ? $q->where('is_active', 1) : $q)
            ->type($type)
            ->search($search)
            ->orderBy('name');

        if ($light) {
            return $query->limit($perPage)->get()->map(function (Customer $customer) {
                return [
                    'id' => $customer->id,
                    'text' => $customer->name,
                    'phone' => $customer->phone,
                    'customer_type' => $customer->customer_type,
                    'is_active' => (bool) $customer->is_active,
                ];
            });
        }

        return $query->paginate($perPage);
    }

    public function show(Customer $customer)
    {
        return $customer;
    }

    public function store(CustomerStoreRequest $request)
    {
        $data = $request->validated();
        $data = $this->applyCreditPolicy($data);
        if (\Illuminate\Support\Facades\Schema::hasColumn('customers', 'created_by')) {
            $data['created_by'] = Illuminate\Support\Facades\Auth::id();
        }

        $customer = Customer::create($data);

        return response()->json($customer, 201);
    }

    public function update(CustomerUpdateRequest $request, Customer $customer)
    {
        $data = $request->validated();
        $data = $this->applyCreditPolicy($data);
        if (\Illuminate\Support\Facades\Schema::hasColumn('customers', 'updated_by')) {
            $data['updated_by'] = Illuminate\Support\Facades\Auth::id();
        }

        $customer->update($data);

        return response()->json($customer);
    }

    public function destroy(Customer $customer)
    {
        $customer->update(['is_active' => false]);

        return response()->json(['message' => __('Customer deactivated.')]);
    }

    private function applyCreditPolicy(array $data): array
    {
        if (($data['customer_type'] ?? Customer::TYPE_RETAIL) === Customer::TYPE_RETAIL) {
            $data['credit_limit'] = 0;
            $data['credit_terms_days'] = 0;
        }

        return $data;
    }
}

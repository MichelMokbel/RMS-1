<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $light = filter_var($request->boolean('light', true), FILTER_VALIDATE_BOOLEAN);
        $status = $request->input('status', 'active');
        $search = $request->input('search');
        $perPage = (int) $request->input('per_page', 20);

        $query = Supplier::query()
            ->with(['paymentTerm', 'defaultExpenseAccount'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', '%'.$search.'%')
                        ->orWhere('contact_person', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('qid_cr', 'like', '%'.$search.'%');
                });
            })
            ->ordered();

        if ($light) {
            return $query->limit($perPage)->get()->map(function (Supplier $supplier) {
                $text = $supplier->name;
                if ($supplier->qid_cr) {
                    $text .= ' (QID/CR: '.$supplier->qid_cr.')';
                }

                return [
                    'id' => $supplier->id,
                    'text' => $text,
                    'preferred_payment_method' => $supplier->preferred_payment_method,
                ];
            });
        }

        return $query->paginate($perPage);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);

        $supplier = Supplier::create($data);

        return response()->json($supplier, 201);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $this->validatedData($request, $supplier->id);

        $supplier->update($data);

        return response()->json($supplier);
    }

    public function destroy(Request $request, Supplier $supplier)
    {
        $forceArchive = $request->boolean('force_archive', false);

        if ($forceArchive) {
            if ($supplier->isInUse()) {
                return response()->json(['message' => __('Supplier is referenced and cannot be archived. Deactivate instead.')], 422);
            }

            if (method_exists($supplier, 'trashed')) {
                $supplier->delete();
                return response()->json(['message' => __('Supplier archived.')]);
            }

            $supplier->status = 'inactive';
            $supplier->save();
            return response()->json(['message' => __('Supplier deactivated.')]);
        }

        $supplier->status = 'inactive';
        $supplier->save();

        return response()->json(['message' => __('Supplier deactivated.')]);
    }

    private function validatedData(Request $request, ?int $ignoreId = null): array
    {
        $rules = [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('suppliers', 'name')->ignore($ignoreId),
            ],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\\-\\s]+$/'],
            'address' => ['nullable', 'string'],
            'qid_cr' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'company_id' => ['nullable', 'integer', 'exists:accounting_companies,id'],
            'payment_term_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'default_expense_account_id' => ['nullable', 'integer', 'exists:ledger_accounts,id'],
            'preferred_payment_method' => ['nullable', Rule::in(['cash', 'bank_transfer', 'card', 'cheque', 'other', 'petty_cash'])],
            'hold_status' => ['nullable', Rule::in(['open', 'hold', 'blocked'])],
            'requires_1099' => ['nullable', 'boolean'],
            'approval_threshold' => ['nullable', 'numeric', 'min:0'],
        ];

        $validated = $request->validate($rules);

        if (empty($validated['status'])) {
            $validated['status'] = 'active';
        }
        if (empty($validated['hold_status'])) {
            $validated['hold_status'] = 'open';
        }

        return $validated;
    }
}

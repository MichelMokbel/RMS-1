<?php

namespace App\Http\Controllers\AP;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\AP\ApPaymentWorkspaceQueryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApPaymentRegisterPrintController extends Controller
{
    public function __invoke(Request $request, ApPaymentWorkspaceQueryService $queries): View
    {
        $filters = $request->validate([
            'payment_supplier_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['nullable', Rule::in(['cash', 'bank_transfer', 'card', 'cheque', 'other', 'petty_cash'])],
            'payment_date_from' => ['nullable', 'date_format:Y-m-d'],
            'payment_date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (isset($filters['payment_date_from'], $filters['payment_date_to']) && $filters['payment_date_to'] < $filters['payment_date_from']) {
            throw ValidationException::withMessages([
                'payment_date_to' => __('The payment date to must be on or after the payment date from.'),
            ]);
        }

        return view('payables.payment-register-print', [
            'payments' => $queries->payments($filters)->get(),
            'filterLabels' => $this->filterLabels($filters),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function filterLabels(array $filters): array
    {
        $labels = [];
        $supplierId = $filters['payment_supplier_id'] ?? null;

        if ($supplierId) {
            $labels[__('Supplier')] = (string) (Supplier::query()->whereKey($supplierId)->value('name') ?? $supplierId);
        }

        if ($method = $filters['payment_method'] ?? null) {
            $labels[__('Method')] = str($method)->replace('_', ' ')->headline()->toString();
        }

        if ($dateFrom = $filters['payment_date_from'] ?? null) {
            $labels[__('Date From')] = $dateFrom;
        }

        if ($dateTo = $filters['payment_date_to'] ?? null) {
            $labels[__('Date To')] = $dateTo;
        }

        return $labels;
    }
}

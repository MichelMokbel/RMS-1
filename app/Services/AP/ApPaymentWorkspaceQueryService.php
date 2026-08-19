<?php

namespace App\Services\AP;

use App\Models\ApPayment;
use Illuminate\Database\Eloquent\Builder;

class ApPaymentWorkspaceQueryService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function payments(array $filters): Builder
    {
        return ApPayment::query()
            ->with(['supplier', 'company'])
            ->withSum('allocations as alloc_sum', 'allocated_amount')
            ->when($filters['payment_supplier_id'] ?? null, fn (Builder $q, $value) => $q->where('supplier_id', $value))
            ->when($filters['payment_method'] ?? null, fn (Builder $q, $value) => $q->where('payment_method', $value))
            ->when($filters['payment_date_from'] ?? null, fn (Builder $q, $value) => $q->whereDate('payment_date', '>=', $value))
            ->when($filters['payment_date_to'] ?? null, fn (Builder $q, $value) => $q->whereDate('payment_date', '<=', $value))
            ->orderByDesc('payment_date')
            ->orderByDesc('id');
    }
}

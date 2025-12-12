<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Models\ApPayment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ApReportsService
{
    public function agingSummary(?int $supplierId = null): array
    {
        $query = ApInvoice::query()
            ->whereNotIn('status', ['void', 'paid'])
            ->where(function ($q) {
                $q->where('status', '!=', 'draft');
            })
            ->withSum('allocations as paid_sum', 'allocated_amount');

        if ($supplierId) {
            $query->where('supplier_id', $supplierId);
        }

        $buckets = [
            'current' => 0,
            '1_30' => 0,
            '31_60' => 0,
            '61_90' => 0,
            '90_plus' => 0,
        ];

        $today = now()->startOfDay();
        foreach ($query->get() as $inv) {
            $paid = (float) $inv->paid_sum;
            $outstanding = max((float) $inv->total_amount - $paid, 0);
            $days = $today->diffInDays($inv->due_date, false) * -1;

            if ($days <= 0) {
                $buckets['current'] += $outstanding;
            } elseif ($days <= 30) {
                $buckets['1_30'] += $outstanding;
            } elseif ($days <= 60) {
                $buckets['31_60'] += $outstanding;
            } elseif ($days <= 90) {
                $buckets['61_90'] += $outstanding;
            } else {
                $buckets['90_plus'] += $outstanding;
            }
        }

        return $buckets;
    }

    public function invoiceRegister(array $filters): LengthAwarePaginator
    {
        $query = ApInvoice::query()
            ->with(['supplier'])
            ->withSum('allocations as paid_sum', 'allocated_amount');

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['invoice_date_from'])) {
            $query->whereDate('invoice_date', '>=', $filters['invoice_date_from']);
        }
        if (! empty($filters['invoice_date_to'])) {
            $query->whereDate('invoice_date', '<=', $filters['invoice_date_to']);
        }
        if (! empty($filters['due_date_from'])) {
            $query->whereDate('due_date', '>=', $filters['due_date_from']);
        }
        if (! empty($filters['due_date_to'])) {
            $query->whereDate('due_date', '<=', $filters['due_date_to']);
        }

        $query->select('*', DB::raw('0 as outstanding_dummy'));

        return $query->orderByDesc('invoice_date')->paginate($filters['per_page'] ?? 15);
    }

    public function paymentRegister(array $filters): LengthAwarePaginator
    {
        $query = ApPayment::query()->with(['supplier'])->withSum('allocations as allocated_sum', 'allocated_amount');

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }
        if (! empty($filters['payment_date_from'])) {
            $query->whereDate('payment_date', '>=', $filters['payment_date_from']);
        }
        if (! empty($filters['payment_date_to'])) {
            $query->whereDate('payment_date', '<=', $filters['payment_date_to']);
        }
        if (! empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        return $query->orderByDesc('payment_date')->paginate($filters['per_page'] ?? 15);
    }
}

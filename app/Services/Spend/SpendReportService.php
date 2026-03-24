<?php

namespace App\Services\Spend;

use App\Models\ApInvoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SpendReportService
{
    public function collect(array $filters = [], int $limit = 500): Collection
    {
        $limit = max(1, min($limit, 5000));

        return $this->apRows($filters, $limit)
            ->sortByDesc(fn (array $row) => [$row['date'] ?? '', $row['id'] ?? 0])
            ->values()
            ->take($limit);
    }

    public function totalForMonth(\DateTimeInterface $start, \DateTimeInterface $end): float
    {
        return $this->totalForRange($start, $end);
    }

    public function totalForRange(\DateTimeInterface $start, \DateTimeInterface $end): float
    {
        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');

        if (! Schema::hasTable('ap_invoices')) {
            return 0;
        }

        $total = (float) ApInvoice::query()
            ->where('is_expense', true)
            ->whereNotIn('status', ['draft', 'void'])
            ->whereDate('invoice_date', '>=', $startDate)
            ->whereDate('invoice_date', '<=', $endDate)
            ->sum('total_amount');

        return round($total, 2);
    }

    private function apRows(array $filters, int $limit): Collection
    {
        if (! Schema::hasTable('ap_invoices')) {
            return collect();
        }

        $paymentStatus = (string) ($filters['payment_status'] ?? 'all');
        $source = (string) ($filters['source'] ?? 'all');
        $sourceChannel = $this->channelFromSource($source);

        return ApInvoice::query()
            ->where('is_expense', true)
            ->whereNotIn('status', ['void'])
            ->with(['supplier', 'category', 'items', 'allocations.payment', 'expenseProfile.wallet'])
            ->when(! empty($filters['company_id']), fn ($q) => $q->where('company_id', (int) $filters['company_id']))
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('branch_id', (int) $filters['branch_id']))
            ->when(! empty($filters['department_id']), fn ($q) => $q->where('department_id', (int) $filters['department_id']))
            ->when(! empty($filters['job_id']), fn ($q) => $q->where('job_id', (int) $filters['job_id']))
            ->when($sourceChannel, fn ($q) => $q->whereHas('expenseProfile', fn ($sub) => $sub->where('channel', $sourceChannel)))
            ->when(! empty($filters['search']), fn ($q) => $q->where(function ($sub) use ($filters) {
                $search = (string) $filters['search'];
                $sub->where('invoice_number', 'like', '%'.$search.'%')
                    ->orWhere('notes', 'like', '%'.$search.'%');
            }))
            ->when(! empty($filters['supplier_id']), fn ($q) => $q->where('supplier_id', (int) $filters['supplier_id']))
            ->when(! empty($filters['category_id']), fn ($q) => $q->where('category_id', (int) $filters['category_id']))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('invoice_date', '>=', (string) $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('invoice_date', '<=', (string) $filters['date_to']))
            ->when(! empty($filters['payment_method']) && $filters['payment_method'] !== 'all', fn ($q) => $q->whereHas('allocations.payment', fn ($sub) => $sub->where('payment_method', (string) $filters['payment_method'])))
            ->when($paymentStatus === 'paid', fn ($q) => $q->where('status', 'paid'))
            ->when(in_array($paymentStatus, ['partial', 'partially_paid'], true), fn ($q) => $q->where('status', 'partially_paid'))
            ->when($paymentStatus === 'unpaid', fn ($q) => $q->where('status', 'posted'))
            ->when(in_array($paymentStatus, ['draft', 'submitted', 'manager_approved', 'approved', 'rejected'], true), fn ($q) => $q->whereHas('expenseProfile', fn ($sub) => $sub->where('approval_status', $paymentStatus)))
            ->orderByDesc('invoice_date')
            ->limit($limit)
            ->get()
            ->map(function (ApInvoice $i) {
                $firstLine = $i->items->first();
                $paymentMethod = (string) optional(optional($i->allocations->first())->payment)->payment_method;
                $profile = $i->expenseProfile;

                return [
                    'id' => 'ap-'.$i->id,
                    'source' => (string) ($profile?->channel ?? 'vendor'),
                    'date' => optional($i->invoice_date)->toDateString(),
                    'reference' => (string) ($i->invoice_number ?? ''),
                    'description' => (string) ($firstLine?->description ?? $i->notes ?? ''),
                    'supplier_id' => $i->supplier_id,
                    'supplier' => (string) ($i->supplier?->name ?? ''),
                    'category' => (string) ($i->category?->name ?? ''),
                    'status' => (string) ($profile?->approval_status ?? $i->status ?? ''),
                    'amount' => (float) ($i->total_amount ?? 0),
                    'payment_method' => $paymentMethod,
                    'company_id' => $i->company_id,
                    'branch_id' => $i->branch_id,
                    'department_id' => $i->department_id,
                    'job_id' => $i->job_id,
                ];
            });
    }

    private function channelFromSource(string $source): ?string
    {
        return match ($source) {
            'petty_cash' => 'petty_cash',
            'vendor' => 'vendor',
            'reimbursement' => 'reimbursement',
            default => null,
        };
    }
}

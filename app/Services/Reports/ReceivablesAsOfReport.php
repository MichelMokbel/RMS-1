<?php

namespace App\Services\Reports;

use App\Models\ArInvoice;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReceivablesAsOfReport
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function asOf(array $filters): Carbon
    {
        $value = trim((string) ($filters['as_of_date'] ?? ''));

        return ($value !== '' ? Carbon::parse($value) : now())->endOfDay();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(array $filters): Collection
    {
        $asOf = $this->asOf($filters);
        $asOfDate = $asOf->toDateString();
        $asOfDateTime = $asOf->toDateTimeString();
        $branchId = (int) ($filters['branch_id'] ?? 0);
        $customerId = (int) ($filters['customer_id'] ?? 0);

        $invoices = ArInvoice::query()
            ->with(['customer:id,name,customer_code'])
            ->where('type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid', 'paid', 'voided'])
            ->whereDate('issue_date', '<=', $asOfDate)
            ->where(function ($query) use ($asOfDateTime) {
                $query->whereNull('voided_at')
                    ->orWhere('voided_at', '>', $asOfDateTime);
            })
            ->when($branchId > 0, fn ($query) => $query->where('branch_id', $branchId))
            ->when($customerId > 0, fn ($query) => $query->where('customer_id', $customerId))
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        if ($invoices->isEmpty()) {
            return collect();
        }

        $paidByInvoice = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->whereIn('pa.allocatable_id', $invoices->pluck('id'))
            ->where('pa.allocatable_type', ArInvoice::class)
            ->where('p.source', 'ar')
            ->where('p.received_at', '<=', $asOfDateTime)
            ->where(function ($query) use ($asOfDateTime) {
                $query->whereNull('p.voided_at')
                    ->orWhere('p.voided_at', '>', $asOfDateTime);
            })
            ->where(function ($query) use ($asOfDateTime) {
                $query->whereNull('pa.voided_at')
                    ->orWhere('pa.voided_at', '>', $asOfDateTime);
            })
            ->groupBy('pa.allocatable_id')
            ->selectRaw('pa.allocatable_id, COALESCE(SUM(pa.amount_cents), 0) as paid_cents')
            ->pluck('paid_cents', 'allocatable_id');

        return $invoices
            ->map(function (ArInvoice $invoice) use ($paidByInvoice, $asOf): array {
                $totalCents = (int) ($invoice->total_cents ?? 0);
                $paidCents = (int) ($paidByInvoice->get($invoice->id, 0) ?? 0);
                $balanceCents = $totalCents - $paidCents;
                $dueDate = $invoice->due_date ?: $invoice->issue_date;
                $days = $dueDate ? (int) floor((float) $dueDate->diffInDays($asOf->copy()->startOfDay(), false)) : 0;

                return [
                    'invoice_id' => (int) $invoice->id,
                    'customer_id' => (int) $invoice->customer_id,
                    'customer_code' => $invoice->customer?->customer_code,
                    'customer_name' => $invoice->customer?->name ?? '-',
                    'invoice_number' => $invoice->invoice_number ?: (string) $invoice->id,
                    'issue_date' => $invoice->issue_date?->format('Y-m-d'),
                    'due_date' => $invoice->due_date?->format('Y-m-d'),
                    'total_cents' => $totalCents,
                    'paid_as_of_cents' => $paidCents,
                    'balance_as_of_cents' => $balanceCents,
                    'aging_label' => $days <= 0 ? __('Not Due') : $days.' '.__('Days'),
                ];
            })
            ->filter(fn (array $row): bool => (int) $row['balance_as_of_cents'] > 0)
            ->sortBy([
                ['customer_name', 'asc'],
                ['issue_date', 'asc'],
                ['invoice_id', 'asc'],
            ])
            ->values();
    }
}

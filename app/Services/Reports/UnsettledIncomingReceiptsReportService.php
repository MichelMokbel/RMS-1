<?php

namespace App\Services\Reports;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;

class UnsettledIncomingReceiptsReportService
{
    public function query(
        ?int $companyId = null,
        ?string $method = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?array $branchIds = null,
    ): Builder {
        return Payment::query()
            ->with('customer')
            ->where('source', 'ar')
            ->when($method, fn ($q) => $q->where('method', $method))
            ->when(! $method, fn ($q) => $q->whereIn('method', ['card', 'cheque']))
            ->whereNull('voided_at')
            ->where(function ($q) use ($toDate) {
                // Unsettled = never settled, OR settled only after the asOf date
                $q->whereNull('clearing_settled_at');
                if ($toDate) {
                    $q->orWhereHas('clearingSettlementItems.settlement', function ($sq) use ($toDate) {
                        $sq->whereNull('voided_at')
                            ->whereDate('settlement_date', '>', $toDate);
                    });
                }
            })
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($branchIds !== null, fn ($q) => $branchIds === []
                ? $q->whereRaw('1 = 0')
                : $q->whereIn('branch_id', $branchIds))
            ->when($fromDate, fn ($q) => $q->whereDate('received_at', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('received_at', '<=', $toDate))
            ->orderBy('received_at');
    }

    public function summary(?int $companyId = null, ?string $asOfDate = null, ?array $branchIds = null): array
    {
        $scale = max((int) config('pos.money_scale', 100), 1);
        $toDate = $asOfDate ?? now()->toDateString();
        $rows = $this->query($companyId, null, null, $toDate, $branchIds)->get();
        $skipCash = Payment::query()
            ->with('clearingSettlementItems.settlement')
            ->where('source', 'ar')
            ->where('method', 'skipcash')
            ->whereNull('voided_at')
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($branchIds !== null, fn ($query) => $branchIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('branch_id', $branchIds))
            ->whereDate('received_at', '<=', $toDate)
            ->get();
        $correctionRequired = $skipCash->filter(fn (Payment $payment): bool => $payment->clearingSettlementItems
            ->contains(fn ($item): bool => $item->settlement?->settlement_method === 'skipcash'
                && $item->settlement?->settlement_date?->toDateString() <= $toDate
                && $item->settlement?->voided_at !== null
                && $item->settlement->voided_at->toDateString() <= $toDate));
        $pendingSkipCash = $skipCash->filter(function (Payment $payment) use ($correctionRequired, $toDate): bool {
            if ($correctionRequired->contains('id', $payment->id)) {
                return false;
            }

            $settledAsOf = $payment->clearingSettlementItems->contains(fn ($item): bool => $item->settlement?->settlement_method === 'skipcash'
                && $item->settlement?->settlement_date?->toDateString() <= $toDate
                && ($item->settlement?->voided_at === null || $item->settlement->voided_at->toDateString() > $toDate));

            return ! $settledAsOf;
        });

        return [
            'as_of' => $toDate,
            'card_count' => $rows->where('method', 'card')->count(),
            'card_total' => round($rows->where('method', 'card')->sum('amount_cents') / $scale, 2),
            'cheque_count' => $rows->where('method', 'cheque')->count(),
            'cheque_total' => round($rows->where('method', 'cheque')->sum('amount_cents') / $scale, 2),
            'skipcash_pending_gross_cents' => (int) $pendingSkipCash->sum('amount_cents'),
            'skipcash_correction_required_gross_cents' => (int) $correctionRequired->sum('amount_cents'),
            'skipcash_unsettled_gross_cents' => (int) $pendingSkipCash->sum('amount_cents')
                + (int) $correctionRequired->sum('amount_cents'),
        ];
    }
}

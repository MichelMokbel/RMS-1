<?php

namespace App\Services\Reports;

use App\Models\ApPayment;
use Illuminate\Database\Eloquent\Builder;

class OutstandingIssuedChequesReportService
{
    public function query(
        ?int $companyId = null,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): Builder {
        return ApPayment::query()
            ->with('supplier')
            ->where('payment_method', 'cheque')
            ->whereNull('voided_at')
            ->whereNull('cheque_cleared_at')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($fromDate, fn ($q) => $q->whereDate('payment_date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('payment_date', '<=', $toDate))
            ->orderBy('payment_date');
    }

    public function summary(?int $companyId = null, ?string $asOfDate = null): array
    {
        $toDate = $asOfDate ?? now()->toDateString();
        $rows = $this->query($companyId, null, $toDate)->get();

        return [
            'as_of' => $toDate,
            'count' => $rows->count(),
            'total' => round((float) $rows->sum('amount'), 2),
        ];
    }
}

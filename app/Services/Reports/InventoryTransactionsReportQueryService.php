<?php

namespace App\Services\Reports;

use App\Services\Inventory\InventoryTransactionQueryService;
use Illuminate\Database\Eloquent\Builder;

class InventoryTransactionsReportQueryService
{
    public function __construct(
        private readonly InventoryTransactionQueryService $transactions,
    ) {
    }

    public function query(array $filters): Builder
    {
        return $this->transactions->query($filters);
    }
}

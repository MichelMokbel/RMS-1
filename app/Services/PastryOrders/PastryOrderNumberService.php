<?php

namespace App\Services\PastryOrders;

use App\Services\Sequences\DocumentSequenceService;

class PastryOrderNumberService
{
    public function __construct(
        protected DocumentSequenceService $sequences,
    ) {}

    public function generate(): string
    {
        $year = now()->format('Y');
        $seq  = $this->sequences->next('pastry_order', 0, $year);

        return 'PST'.$year.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}

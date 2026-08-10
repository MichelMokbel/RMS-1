<?php

namespace App\Console\Commands;

use App\Services\Quotations\QuotationLifecycleService;
use Illuminate\Console\Command;

class ExpireQuotations extends Command
{
    protected $signature = 'quotations:expire';

    protected $description = 'Mark sent quotations past their validity date as expired';

    public function handle(QuotationLifecycleService $lifecycle): int
    {
        $count = $lifecycle->expireOverdue();
        $this->info(trans_choice(':count quotation expired.|:count quotations expired.', $count, ['count' => $count]));

        return self::SUCCESS;
    }
}

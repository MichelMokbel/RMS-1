<?php

namespace App\Enums\HR;

enum PayrollPaymentBatchStatus: string
{
    case Draft = 'draft';
    case Processed = 'processed';
    case PartiallyFailed = 'partially_failed';
    case Reversed = 'reversed';
}

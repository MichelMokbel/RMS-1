<?php

namespace App\Enums\HR;

enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Calculated = 'calculated';
    case Approved = 'approved';
    case Posted = 'posted';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Reversed = 'reversed';
}

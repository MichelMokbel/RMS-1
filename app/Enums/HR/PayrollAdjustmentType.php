<?php

namespace App\Enums\HR;

enum PayrollAdjustmentType: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';
}

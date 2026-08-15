<?php

namespace App\Enums\HR;

enum LeaveLedgerEntryType: string
{
    case Opening = 'opening';
    case Accrual = 'accrual';
    case Adjustment = 'adjustment';
    case Reservation = 'reservation';
    case Usage = 'usage';
    case Reversal = 'reversal';
}

<?php

namespace App\Enums\HR;

enum ImportType: string
{
    case Employees = 'employees';
    case Assignments = 'assignments';
    case Documents = 'documents';
    case Leave = 'leave';
    case Payroll = 'payroll';
    case FullHistory = 'full_history';
}

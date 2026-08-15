<?php

namespace App\Enums\HR;

enum LeaveRequestStatus: string
{
    case Draft = 'draft';
    case PendingManager = 'pending_manager';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}

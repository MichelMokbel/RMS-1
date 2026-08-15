<?php

namespace App\Enums\HR;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Expired = 'expired';
    case Rejected = 'rejected';
    case Archived = 'archived';
}

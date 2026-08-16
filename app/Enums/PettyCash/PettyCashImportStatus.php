<?php

namespace App\Enums\PettyCash;

enum PettyCashImportStatus: string
{
    case Validating = 'validating';
    case Ready = 'ready';
    case Failed = 'failed';
    case Committing = 'committing';
    case Completed = 'completed';
}

<?php

namespace App\Enums\HR;

enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Validating = 'validating';
    case Ready = 'ready';
    case Committing = 'committing';
    case Completed = 'completed';
    case Failed = 'failed';
}

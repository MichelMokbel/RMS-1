<?php

namespace App\Enums\PettyCash;

enum PettyCashImportRowStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Committed = 'committed';
}

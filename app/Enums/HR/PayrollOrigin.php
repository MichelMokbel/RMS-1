<?php

namespace App\Enums\HR;

enum PayrollOrigin: string
{
    case Native = 'native';
    case Migrated = 'migrated';
}

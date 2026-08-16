<?php

namespace App\Enums\PettyCash;

enum PettyCashImportInvoiceStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Committed = 'committed';
}

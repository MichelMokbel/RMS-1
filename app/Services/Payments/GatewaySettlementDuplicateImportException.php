<?php

namespace App\Services\Payments;

use App\Models\GatewaySettlementImport;
use RuntimeException;

class GatewaySettlementDuplicateImportException extends RuntimeException
{
    public function __construct(public readonly GatewaySettlementImport $existingImport)
    {
        parent::__construct('This SkipCash report has already been imported.');
    }
}

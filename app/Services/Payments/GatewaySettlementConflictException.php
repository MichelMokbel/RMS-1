<?php

namespace App\Services\Payments;

use RuntimeException;

class GatewaySettlementConflictException extends RuntimeException
{
    public function __construct(public readonly string $conflictCode, string $message)
    {
        parent::__construct($message);
    }
}

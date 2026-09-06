<?php

namespace App\Services\Promotions;

use RuntimeException;

class PromotionConflictException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $currentRevision = null)
    {
        parent::__construct($message, 409);
    }
}

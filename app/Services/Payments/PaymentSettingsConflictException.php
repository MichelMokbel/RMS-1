<?php

namespace App\Services\Payments;

use RuntimeException;

class PaymentSettingsConflictException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $current
     */
    public function __construct(public readonly array $current)
    {
        parent::__construct(__('Payment settings changed after this form was opened. Review the current values and try again.'));
    }
}

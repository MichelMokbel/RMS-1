<?php

namespace App\Services\Mail;

use RuntimeException;

class MailSettingsConflictException extends RuntimeException
{
    /** @param array<string, mixed> $current */
    public function __construct(public readonly array $current)
    {
        parent::__construct(__('Mail settings changed in another session. Review the latest values and try again.'));
    }
}

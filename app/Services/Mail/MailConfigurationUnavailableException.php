<?php

namespace App\Services\Mail;

use RuntimeException;

class MailConfigurationUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode = 'MAIL_CONFIGURATION_UNAVAILABLE')
    {
        parent::__construct('Mail configuration is unavailable.');
    }
}

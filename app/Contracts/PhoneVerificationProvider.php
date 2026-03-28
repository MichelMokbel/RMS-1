<?php

namespace App\Contracts;

interface PhoneVerificationProvider
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{provider:string, message_id:string|null}
     */
    public function sendVerificationCode(string $phoneE164, string $message, array $context = []): array;
}

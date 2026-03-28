<?php

namespace Tests\Support;

use App\Contracts\PhoneVerificationProvider;

class FakePhoneVerificationProvider implements PhoneVerificationProvider
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $messages = [];

    public function sendVerificationCode(string $phoneE164, string $message, array $context = []): array
    {
        $this->messages[] = [
            'phone_e164' => $phoneE164,
            'message' => $message,
            'context' => $context,
        ];

        return [
            'provider' => 'fake',
            'message_id' => (string) count($this->messages),
        ];
    }

    public function latestCode(): string
    {
        $message = $this->messages[array_key_last($this->messages)]['message'] ?? '';
        preg_match('/(\d{4,8})/', (string) $message, $matches);

        return (string) ($matches[1] ?? '');
    }
}

<?php

namespace App\Services\Customers;

use App\Contracts\PhoneVerificationProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class TelnyxPhoneVerificationProvider implements PhoneVerificationProvider
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    public function sendVerificationCode(string $phoneE164, string $message, array $context = []): array
    {
        if (preg_match('/^\+[1-9]\d{7,14}$/', $phoneE164) !== 1) {
            throw new RuntimeException('SMS recipient must be in E.164 format.');
        }

        $apiKey = trim((string) config('services.customer_sms.telnyx.api_key'));
        $from = trim((string) config('services.customer_sms.telnyx.from'));
        $baseUrl = rtrim(trim((string) config('services.customer_sms.telnyx.base_url')), '/');
        if ($apiKey === '' || $from === '' || $baseUrl === '') {
            throw new RuntimeException('Telnyx SMS is not configured.');
        }

        $fromIsPhone = preg_match('/^\+[1-9]\d{7,14}$/', $from) === 1;
        $fromIsAlphanumeric = preg_match('/^(?=.*[A-Za-z])[A-Za-z0-9 ]{1,11}$/', $from) === 1;
        if (! $fromIsPhone && ! $fromIsAlphanumeric) {
            throw new RuntimeException('Telnyx SMS sender is invalid.');
        }

        $messagingProfileId = trim((string) config('services.customer_sms.telnyx.messaging_profile_id'));
        if ($fromIsAlphanumeric && $messagingProfileId === '') {
            throw new RuntimeException('Telnyx messaging profile is required for the configured sender.');
        }

        $payload = [
            'from' => $from,
            'to' => $phoneE164,
            'text' => $message,
            'type' => 'SMS',
        ];

        if ($messagingProfileId !== '') {
            $payload['messaging_profile_id'] = $messagingProfileId;
        }

        try {
            $response = $this->http
                ->acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->connectTimeout((int) config('services.customer_sms.telnyx.connect_timeout_seconds', 3))
                ->timeout((int) config('services.customer_sms.telnyx.timeout_seconds', 10))
                ->post(
                    $baseUrl.'/messages',
                    $payload,
                );
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Telnyx SMS request could not be confirmed.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Telnyx rejected the SMS request with HTTP {$response->status()}.");
        }

        $messageId = $response->json('data.id');
        if (! is_string($messageId) || trim($messageId) === '') {
            throw new RuntimeException('Telnyx SMS response did not contain a message identifier.');
        }

        return [
            'provider' => 'telnyx',
            'message_id' => $messageId,
        ];
    }
}

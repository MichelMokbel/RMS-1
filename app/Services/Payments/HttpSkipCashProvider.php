<?php

namespace App\Services\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class HttpSkipCashProvider implements SkipCashProvider
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    public function create(array $request): array
    {
        $signedFields = ['Uid', 'KeyId', 'Amount', 'FirstName', 'LastName', 'Phone', 'Email', 'TransactionId'];
        $authorization = $this->hmacAuthorization($request, $signedFields, (string) config('payments.skipcash.secret_key'));

        $response = $this->http
            ->connectTimeout((int) config('payments.skipcash.connect_timeout_seconds', 2))
            ->timeout((int) config('payments.skipcash.create_timeout_seconds', 8))
            ->withHeaders(['Authorization' => $authorization])
            ->post(rtrim((string) config('payments.skipcash.base_url'), '/').'/api/v1/payments', $request);

        if ($response->status() >= 500) {
            throw new RuntimeException('SkipCash create request was not confirmed.');
        }

        if (! $response->successful()) {
            return [
                'outcome' => 'rejected',
                'status' => (string) $response->status(),
            ];
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('SkipCash create response was malformed.');
        }

        $result = $payload['resultObj'] ?? null;
        if (! is_array($result)) {
            throw new RuntimeException('SkipCash create response did not contain a result.');
        }

        return [
            'outcome' => 'created',
            'provider_payment_id' => (string) ($result['id'] ?? ''),
            'pay_url' => (string) ($result['payUrl'] ?? $payload['payUrl'] ?? ''),
            'merchant_transaction_id' => (string) ($result['transactionId'] ?? $request['TransactionId'] ?? ''),
            'amount' => (string) ($result['amount'] ?? $request['Amount'] ?? ''),
            'currency' => (string) ($result['currency'] ?? 'QAR'),
            'raw_status' => (string) ($result['statusId'] ?? $payload['statusId'] ?? ''),
        ];
    }

    public function details(string $providerPaymentId): array
    {
        $response = $this->http
            ->connectTimeout((int) config('payments.skipcash.connect_timeout_seconds', 2))
            ->timeout((int) config('payments.skipcash.detail_timeout_seconds', 5))
            ->withHeaders(['Authorization' => (string) config('payments.skipcash.client_id')])
            ->get(rtrim((string) config('payments.skipcash.base_url'), '/').'/api/v1/payments/'.$providerPaymentId);

        if (! $response->successful()) {
            throw new RuntimeException('SkipCash payment details were unavailable.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('SkipCash payment details were malformed.');
        }

        $result = $payload['resultObj'] ?? $payload;
        if (! is_array($result)) {
            throw new RuntimeException('SkipCash payment details did not contain a result.');
        }

        return [
            'provider_payment_id' => (string) ($result['id'] ?? $providerPaymentId),
            'merchant_transaction_id' => (string) ($result['transactionId'] ?? ''),
            'amount' => (string) ($result['amount'] ?? ''),
            'currency' => (string) ($result['currency'] ?? ''),
            'status_id' => (string) ($result['statusId'] ?? ''),
            'finished_at' => $this->normalizeFinishedAt($result['finishedDate'] ?? null),
            'visa_id' => $this->nullableString($result['visaId'] ?? null),
            'card_type' => $this->nullableString($result['cardType'] ?? null),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeFinishedAt(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $raw = trim($value);
        if ($raw === '' || preg_match('/Z$/i', $raw) !== 1) {
            return $value;
        }

        try {
            $reportedUtc = CarbonImmutable::parse($raw)->utc();
            $latestPlausible = CarbonImmutable::now('UTC')->addMinutes(5);
            if (! $reportedUtc->greaterThan($latestPlausible)) {
                return $raw;
            }

            $providerWallClock = CarbonImmutable::parse(
                substr($raw, 0, -1),
                (string) config('payments.skipcash.timezone', 'Asia/Qatar'),
            )->utc();

            return $providerWallClock->lessThanOrEqualTo($latestPlausible)
                ? $providerWallClock->toIso8601String()
                : $raw;
        } catch (\Throwable) {
            return $value;
        }
    }

    /** @param array<string, string> $request
     * @param  array<int, string>  $fields
     */
    private function hmacAuthorization(array $request, array $fields, string $secret): string
    {
        $parts = [];
        foreach ($fields as $field) {
            $value = $request[$field] ?? null;
            if ($value !== null && $value !== '') {
                $parts[] = $field.'='.$value;
            }
        }

        return base64_encode(hash_hmac('sha256', implode(',', $parts), $secret, true));
    }
}

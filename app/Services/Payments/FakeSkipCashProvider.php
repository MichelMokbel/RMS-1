<?php

namespace App\Services\Payments;

use Carbon\CarbonImmutable;

class FakeSkipCashProvider implements SkipCashProvider
{
    /** @var array<string, array<string, mixed>> */
    private array $payments = [];

    public function create(array $request): array
    {
        $id = 'fake-'.str_replace('-', '', (string) ($request['Uid'] ?? ''));
        $payment = [
            'provider_payment_id' => $id,
            'merchant_transaction_id' => (string) ($request['TransactionId'] ?? ''),
            'amount' => (string) ($request['Amount'] ?? ''),
            'currency' => 'QAR',
            'status_id' => '0',
            'finished_at' => null,
        ];
        $this->payments[$id] = $payment;

        return [
            'outcome' => 'created',
            ...$payment,
            'pay_url' => 'https://pay.skipcash.test/pay/'.$id,
            'raw_status' => '0',
        ];
    }

    public function details(string $providerPaymentId): array
    {
        if (! isset($this->payments[$providerPaymentId])) {
            throw new \RuntimeException('Unknown fake SkipCash payment.');
        }

        return $this->payments[$providerPaymentId];
    }

    public function markPaid(string $providerPaymentId, ?CarbonImmutable $finishedAt = null): void
    {
        if (! isset($this->payments[$providerPaymentId])) {
            throw new \RuntimeException('Unknown fake SkipCash payment.');
        }

        $this->payments[$providerPaymentId]['status_id'] = '2';
        $this->payments[$providerPaymentId]['finished_at'] = ($finishedAt ?? now('Asia/Qatar'))->toIso8601String();
    }
}

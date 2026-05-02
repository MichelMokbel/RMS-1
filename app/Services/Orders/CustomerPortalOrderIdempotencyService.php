<?php

namespace App\Services\Orders;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerPortalOrderIdempotencyService
{
    private const REPLAY_TTL_SECONDS = 600;

    /**
     * @param  array<string, mixed>  $payload
     * @param  \Closure():array<string, mixed>  $create
     * @return array{response:array<string,mixed>,replayed:bool,replayed_audit_id:?string,key_type:string}
     */
    public function execute(User $user, array $payload, \Closure $create): array
    {
        $clientUuid = trim((string) ($payload['client_uuid'] ?? ''));
        $payloadFingerprint = $this->payloadFingerprint($payload);
        $keyType = $clientUuid !== '' ? 'client_uuid' : 'payload_fingerprint';
        $submissionKey = $clientUuid !== '' ? $clientUuid : $payloadFingerprint;

        $cacheKey = $this->cacheKey($user->id, $keyType, $submissionKey);
        $lockKey = $cacheKey.':lock';

        try {
            return Cache::lock($lockKey, 30)->block(10, function () use ($cacheKey, $payloadFingerprint, $clientUuid, $keyType, $create) {
                /** @var array<string,mixed>|null $cached */
                $cached = Cache::get($cacheKey);
                if (is_array($cached)) {
                    $cachedFingerprint = (string) ($cached['payload_fingerprint'] ?? '');
                    if ($clientUuid !== '' && $cachedFingerprint !== '' && $cachedFingerprint !== $payloadFingerprint) {
                        throw ValidationException::withMessages([
                            'client_uuid' => __('The supplied submission idempotency key belongs to a different order request.'),
                        ]);
                    }

                    return [
                        'response' => Arr::except($cached, ['payload_fingerprint']),
                        'replayed' => true,
                        'replayed_audit_id' => (string) ($cached['audit_id'] ?? ''),
                        'key_type' => $keyType,
                    ];
                }

                $response = $create();
                $cachePayload = $response + ['payload_fingerprint' => $payloadFingerprint];
                Cache::put($cacheKey, $cachePayload, now()->addSeconds(self::REPLAY_TTL_SECONDS));

                return [
                    'response' => $response,
                    'replayed' => false,
                    'replayed_audit_id' => null,
                    'key_type' => $keyType,
                ];
            });
        } catch (LockTimeoutException $e) {
            throw ValidationException::withMessages([
                'submission' => __('Another identical order submission is already being processed. Please wait and retry.'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function payloadFingerprint(array $payload): string
    {
        return hash('sha256', json_encode($this->normalizePayload($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $normalizedItems = collect((array) ($payload['items'] ?? []))
            ->map(function ($item) {
                $item = is_array($item) ? $item : [];
                $mains = collect((array) ($item['mains'] ?? []))
                    ->map(fn ($main) => [
                        'name' => trim((string) ($main['name'] ?? $main['main'] ?? '')),
                        'portion' => strtolower(trim((string) ($main['portion'] ?? 'plate'))),
                        'qty' => (int) ($main['qty'] ?? 0),
                    ])
                    ->sortBy(fn (array $main) => implode('|', [$main['name'], $main['portion'], $main['qty']]))
                    ->values()
                    ->all();

                return [
                    'key' => trim((string) ($item['key'] ?? '')),
                    'mains' => $mains,
                    'salad' => trim((string) ($item['salad'] ?? '')),
                    'dessert' => trim((string) ($item['dessert'] ?? '')),
                    'salad_qty' => (int) ($item['salad_qty'] ?? 0),
                    'dessert_qty' => (int) ($item['dessert_qty'] ?? 0),
                    'notes' => trim((string) ($item['notes'] ?? '')),
                    'mealType' => trim((string) ($item['mealType'] ?? '')),
                    'main' => trim((string) ($item['main'] ?? '')),
                    'menu_item_id' => isset($item['menu_item_id']) ? (int) $item['menu_item_id'] : null,
                    'day_total' => isset($item['day_total']) ? round((float) $item['day_total'], 3) : null,
                ];
            })
            ->sortBy(fn (array $item) => implode('|', [
                $item['key'],
                md5(json_encode($item['mains'])),
                $item['salad_qty'],
                $item['dessert_qty'],
                $item['mealType'],
                $item['main'],
                (string) $item['menu_item_id'],
            ]))
            ->values()
            ->all();

        return [
            'customerName' => trim((string) ($payload['customerName'] ?? '')),
            'phone' => trim((string) ($payload['phone'] ?? '')),
            'email' => trim((string) ($payload['email'] ?? '')),
            'address' => trim((string) ($payload['address'] ?? '')),
            'notes' => trim((string) ($payload['notes'] ?? '')),
            'mealPlan' => trim((string) ($payload['mealPlan'] ?? '')),
            'items' => $normalizedItems,
        ];
    }

    private function cacheKey(int $userId, string $keyType, string $submissionKey): string
    {
        return sprintf('customer-portal-order:%d:%s:%s', $userId, $keyType, $submissionKey);
    }
}

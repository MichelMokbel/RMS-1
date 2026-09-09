<?php

namespace App\Services\Storefront;

use App\Models\StorefrontEvent;
use App\Services\Accounting\AccountingContextService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StorefrontEventService
{
    /** @var array<string, array<int, string>> */
    private const CONTEXT_FIELDS = [
        'storefront_viewed' => ['path_code'],
        'path_selected' => ['path_code'],
        'category_viewed' => ['category_id'],
        'search_used' => ['result_count', 'query_length'],
        'item_viewed' => ['profile_id', 'source_section'],
        'item_added' => ['profile_id', 'source_section', 'quantity_bucket'],
        'item_removed' => ['profile_id'],
        'cart_viewed' => ['line_count'],
        'service_date_selected' => ['lead_day_count'],
        'delivery_app_opened' => ['channel_code', 'profile_id', 'source_section'],
        'upsell_viewed' => ['path_code', 'line_count'],
        'upsell_skipped' => ['path_code'],
        'upsell_item_added' => ['path_code', 'profile_id'],
    ];

    private const PATH_CODES = [
        'order_home', 'daily_dish', 'advance_menu', 'membership_purchase', 'membership_booking', 'memberships', 'account', 'payment_recovery', 'delivery_apps',
    ];

    private const SOURCE_SECTIONS = [
        'order_home', 'menu_list', 'menu_search', 'menu_detail', 'popular', 'chef_picks', 'cart', 'delivery_apps',
    ];

    public function __construct(
        private readonly AccountingContextService $accountingContext,
    ) {}

    /** @param array<string, mixed> $payload
     * @return array{event:StorefrontEvent,duplicate:bool}
     */
    public function record(array $payload): array
    {
        $eventName = trim((string) ($payload['event_name'] ?? ''));
        $allowedContext = self::CONTEXT_FIELDS[$eventName] ?? null;
        if ($allowedContext === null) {
            throw ValidationException::withMessages(['event_name' => __('Choose a supported storefront event.')]);
        }
        $allowedKeys = array_merge(['event_uuid', 'journey_uuid', 'event_name'], $allowedContext);
        if (array_diff(array_keys($payload), $allowedKeys) !== []) {
            throw ValidationException::withMessages(['event' => __('Unsupported storefront event fields were submitted.')]);
        }
        $eventUuid = strtolower(trim((string) ($payload['event_uuid'] ?? '')));
        $journeyUuid = strtolower(trim((string) ($payload['journey_uuid'] ?? '')));
        if (! Str::isUuid($eventUuid) || ! Str::isUuid($journeyUuid)) {
            throw ValidationException::withMessages(['event_uuid' => __('Valid event and journey identifiers are required.')]);
        }

        $companyId = (int) $this->accountingContext->defaultCompanyId();
        $appKey = (string) config('app.key');
        if ($companyId <= 0 || $appKey === '') {
            throw ValidationException::withMessages(['event' => __('Storefront measurement is unavailable.')]);
        }
        $attributes = $this->validateContext($eventName, Arr::only($payload, $allowedContext), $companyId);

        return DB::transaction(function () use ($companyId, $eventUuid, $journeyUuid, $eventName, $attributes, $appKey): array {
            $existing = StorefrontEvent::query()->where('event_uuid', $eventUuid)->lockForUpdate()->first();
            if ($existing) {
                return ['event' => $existing, 'duplicate' => true];
            }
            $event = StorefrontEvent::query()->create([
                'company_id' => $companyId,
                'event_uuid' => $eventUuid,
                'journey_hash' => hash_hmac('sha256', $journeyUuid, $appKey),
                'event_name' => $eventName,
                'source' => 'browser',
                'received_at' => now('UTC'),
                ...$attributes,
            ]);

            return ['event' => $event, 'duplicate' => false];
        }, 3);
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function validateContext(string $eventName, array $context, int $companyId): array
    {
        $required = array_diff(self::CONTEXT_FIELDS[$eventName], $eventName === 'delivery_app_opened' ? ['profile_id'] : []);
        foreach ($required as $field) {
            if (! array_key_exists($field, $context)) {
                throw ValidationException::withMessages([$field => __('This storefront event field is required.')]);
            }
        }
        $integerRanges = [
            'result_count' => [0, 48],
            'query_length' => [0, 120],
            'line_count' => [0, 100],
            'lead_day_count' => [1, 365],
        ];
        foreach ($integerRanges as $field => [$minimum, $maximum]) {
            if (! array_key_exists($field, $context)) {
                continue;
            }
            $value = filter_var($context[$field], FILTER_VALIDATE_INT);
            if ($value === false || $value < $minimum || $value > $maximum) {
                throw ValidationException::withMessages([$field => __('This storefront event value is invalid.')]);
            }
            $context[$field] = (int) $value;
        }
        foreach (['profile_id' => 'storefront_item_profiles', 'category_id' => 'storefront_categories'] as $field => $table) {
            if (! array_key_exists($field, $context) || $context[$field] === null) {
                continue;
            }
            $id = filter_var($context[$field], FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0 || ! DB::table($table)->where('company_id', $companyId)->where('id', $id)->exists()) {
                throw ValidationException::withMessages([$field => __('This storefront event reference is invalid.')]);
            }
            $context[$field] = (int) $id;
        }
        if (isset($context['path_code']) && ! in_array($context['path_code'], self::PATH_CODES, true)) {
            throw ValidationException::withMessages(['path_code' => __('This storefront path is invalid.')]);
        }
        if (isset($context['source_section']) && ! in_array($context['source_section'], self::SOURCE_SECTIONS, true)) {
            throw ValidationException::withMessages(['source_section' => __('This storefront section is invalid.')]);
        }
        if (isset($context['channel_code']) && ! in_array($context['channel_code'], ['talabat', 'snoonu', 'rafeeq', 'keeta'], true)) {
            throw ValidationException::withMessages(['channel_code' => __('This delivery application is invalid.')]);
        }
        if (isset($context['quantity_bucket']) && ! in_array($context['quantity_bucket'], ['under_one', 'one', 'two_to_three', 'four_plus'], true)) {
            throw ValidationException::withMessages(['quantity_bucket' => __('This quantity bucket is invalid.')]);
        }

        return $context;
    }
}

<?php

namespace App\Services\Storefront;

use App\Models\MenuItem;
use App\Models\StorefrontItemProfile;
use App\Models\User;
use App\Services\Customers\CustomerIdentityResolver;
use App\Services\Payments\CheckoutCanonicalizer;
use App\Services\Payments\PaymentCheckoutException;
use App\Services\Payments\PaymentTermsService;
use App\Support\Money\MinorUnits;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class StorefrontMenuQuoteService
{
    private const MAX_SAFE_CENTS = 9007199254740991;

    public function __construct(
        private readonly StorefrontContextService $contexts,
        private readonly StorefrontCatalogService $catalog,
        private readonly StorefrontAvailabilityService $availability,
        private readonly CustomerIdentityResolver $identityResolver,
        private readonly PaymentTermsService $paymentTerms,
        private readonly CheckoutCanonicalizer $canonicalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function quote(User $user, array $request, bool $lockForUpdate = false): array
    {
        $user = $this->identityResolver->resolveForCheckout($user);
        $group = $this->normalizeGroup(is_array($request['group'] ?? null) ? $request['group'] : []);
        $previousFingerprint = strtolower(trim((string) ($request['previous_quote_fingerprint'] ?? '')));
        if ($previousFingerprint !== '' && ! preg_match('/^[a-f0-9]{64}$/', $previousFingerprint)) {
            throw ValidationException::withMessages([
                'previous_quote_fingerprint' => __('The previous menu quote is invalid.'),
            ]);
        }

        $context = $this->contexts->directMenu($lockForUpdate);
        $terms = $this->paymentTerms->inspect()['current'];
        if (! $terms) {
            throw new PaymentCheckoutException(
                'PAYMENT_CONTEXT_UNAVAILABLE',
                503,
                __('Checkout is currently unavailable.'),
            );
        }

        $requestedLines = array_merge(
            array_map(fn (array $item): array => $item + ['line_role' => 'menu_item'], $group['items']),
            array_map(fn (array $item): array => $item + ['line_role' => 'checkout_add_on'], $group['add_ons']),
        );
        $requestedIds = array_column($requestedLines, 'menu_item_id');
        if ($lockForUpdate) {
            MenuItem::query()
                ->whereIn('id', $requestedIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
        }
        $profileQuery = $this->catalog
            ->eligibleQuery((int) $context['company_id'], (int) $context['branch']->id)
            ->whereIn('menu_item_id', $requestedIds)
            ->orderBy('menu_item_id');
        if ($lockForUpdate) {
            $profileQuery->lockForUpdate();
        }
        $profiles = $profileQuery->get()->keyBy('menu_item_id');

        $lines = [];
        $invalidItemIds = [];
        $earliestDate = null;
        foreach ($requestedLines as $item) {
            /** @var StorefrontItemProfile|null $profile */
            $profile = $profiles->get($item['menu_item_id']);
            $isConfiguredUpsell = $item['line_role'] !== 'checkout_add_on'
                || ($context['storefront']->checkout_upsell_enabled
                    && (int) $profile?->category_id === (int) $context['storefront']->upsell_category_id);
            if (! $profile || ! $isConfiguredUpsell || ! $this->quantityIsValid((string) $item['quantity'], $profile)) {
                $invalidItemIds[] = (int) $item['menu_item_id'];

                continue;
            }

            $unitPriceCents = MinorUnits::parse((string) $profile->menuItem->selling_price_per_unit, 100);
            $quantityMilli = MinorUnits::parseQtyMilli((string) $item['quantity']);
            if ($quantityMilli <= 0 || $unitPriceCents > intdiv(PHP_INT_MAX, $quantityMilli)) {
                $invalidItemIds[] = (int) $item['menu_item_id'];

                continue;
            }
            $lineTotalCents = MinorUnits::mulQty($unitPriceCents, $quantityMilli);
            if ($unitPriceCents <= 0 || $lineTotalCents <= 0 || $lineTotalCents > self::MAX_SAFE_CENTS) {
                $invalidItemIds[] = (int) $item['menu_item_id'];

                continue;
            }

            $itemEarliest = $this->availability->earliestDate($context['storefront'], $profile);
            $earliestDate = $earliestDate === null || $itemEarliest > $earliestDate ? $itemEarliest : $earliestDate;
            $title = trim((string) $profile->customer_title) !== ''
                ? (string) $profile->customer_title
                : (string) $profile->menuItem->name;
            $lines[] = [
                'menu_item_id' => (int) $profile->menu_item_id,
                'profile_id' => (int) $profile->id,
                'title' => $title,
                'canonical_name' => (string) $profile->menuItem->name,
                'description' => $profile->short_description,
                'unit' => (string) $profile->menuItem->unit,
                'quantity' => (string) $item['quantity'],
                'unit_price_cents' => $unitPriceCents,
                'line_total_cents' => $lineTotalCents,
                'advance_days' => (int) $profile->advance_days,
                'earliest_service_date' => $itemEarliest,
                'minimum_quantity' => (string) $profile->minimum_quantity,
                'quantity_increment' => (string) $profile->quantity_increment,
                'maximum_quantity' => $profile->maximum_quantity !== null
                    ? (string) $profile->maximum_quantity
                    : null,
                'line_role' => $item['line_role'],
            ];
        }

        $totalCents = array_sum(array_column($lines, 'line_total_cents'));
        $addOnTotalCents = (int) collect($lines)
            ->where('line_role', 'checkout_add_on')
            ->sum('line_total_cents');
        if ($totalCents > self::MAX_SAFE_CENTS) {
            throw ValidationException::withMessages([
                'group.items' => __('The selected menu order exceeds the supported payment amount.'),
            ]);
        }

        $currentGroup = [
            'version' => 'menu-order-v1',
            'service_date' => $group['service_date'],
            'items' => array_map(fn (array $line): array => [
                'menu_item_id' => $line['menu_item_id'],
                'quantity' => $line['quantity'],
            ], array_values(array_filter($lines, fn (array $line): bool => $line['line_role'] === 'menu_item'))),
            'add_ons' => array_map(fn (array $line): array => [
                'menu_item_id' => $line['menu_item_id'],
                'quantity' => $line['quantity'],
            ], array_values(array_filter($lines, fn (array $line): bool => $line['line_role'] === 'checkout_add_on'))),
            'note' => $group['note'],
        ];
        $selectedDateIsClosed = $this->availability->isClosedDate(
            $context['storefront'],
            $group['service_date'],
            $lockForUpdate,
        );
        $resolvedValues = array_map(fn (array $line): array => [
            (string) $line['menu_item_id'],
            (string) $line['profile_id'],
            $line['title'],
            $line['canonical_name'],
            $line['description'],
            $line['unit'],
            $line['quantity'],
            $line['unit_price_cents'],
            $line['line_total_cents'],
            $line['advance_days'],
            $line['earliest_service_date'],
            $line['minimum_quantity'],
            $line['quantity_increment'],
            $line['maximum_quantity'],
            $line['line_role'],
        ], $lines);
        $fingerprint = $this->canonicalizer->hash([
            'menu-order-quote-v1',
            (string) $user->customer_id,
            (string) $context['company_id'],
            (string) $context['branch']->id,
            $currentGroup,
            (int) $context['storefront']->revision,
            $resolvedValues,
            $terms['version'],
            $terms['content_hash'],
            $selectedDateIsClosed,
            $totalCents,
        ]);
        $quote = [
            'purpose' => 'menu_order',
            'group' => $currentGroup,
            'items' => $lines,
            'removed_item_ids' => array_values(array_unique($invalidItemIds)),
            'earliest_service_date' => $earliestDate,
            'selected_service_date_available' => ! $selectedDateIsClosed,
            'gross_amount_cents' => $totalCents,
            'discount_amount_cents' => 0,
            'payable_amount_cents' => $totalCents,
            'add_on_amount_cents' => $addOnTotalCents,
            'currency' => 'QAR',
            'quote_fingerprint' => $fingerprint,
            'terms_version' => $terms['version'],
            'terms_url' => $terms['url'],
            'terms_content_hash' => $terms['content_hash'],
            'support_phone' => $context['payment_settings']->order_support_phone,
            'can_checkout' => $invalidItemIds === []
                && $lines !== []
                && $earliestDate !== null
                && $group['service_date'] >= $earliestDate
                && ! $selectedDateIsClosed
                && $totalCents > 0,
            '_context' => $context + ['terms' => $terms],
            '_resolved_values' => $resolvedValues,
        ];

        if ($previousFingerprint !== '' && (! hash_equals($previousFingerprint, $fingerprint) || ! $quote['can_checkout'])) {
            throw new PaymentCheckoutException(
                'MENU_CART_CHANGED',
                409,
                __('Your menu order changed. Review the current details before paying.'),
                ['quote' => $this->publicQuote($quote)],
            );
        }
        if ($invalidItemIds !== [] || $lines === []) {
            throw ValidationException::withMessages([
                'group.items' => __('One or more selected menu items are unavailable or have an invalid quantity.'),
            ]);
        }
        if ($earliestDate === null || $group['service_date'] < $earliestDate) {
            throw new PaymentCheckoutException(
                'MENU_SERVICE_DATE_INVALID',
                422,
                __('Choose a service date on or after :date.', ['date' => $earliestDate]),
                ['quote' => $this->publicQuote($quote)],
            );
        }
        if ($selectedDateIsClosed) {
            throw new PaymentCheckoutException(
                'MENU_SERVICE_DATE_CLOSED',
                422,
                __('The selected service date is unavailable. Choose another date.'),
                ['quote' => $this->publicQuote($quote)],
            );
        }
        if ($totalCents <= 0) {
            throw ValidationException::withMessages([
                'group.items' => __('The selected menu order has an invalid total.'),
            ]);
        }

        return $quote;
    }

    /** @param array<string, mixed> $group
     * @return array{version:string,service_date:string,items:array<int,array{menu_item_id:int,quantity:string}>,add_ons:array<int,array{menu_item_id:int,quantity:string}>,note:string|null}
     */
    public function normalizeGroup(array $group): array
    {
        $this->assertExactKeys($group, ['version', 'service_date', 'items', 'add_ons', 'note'], 'group');
        if (($group['version'] ?? null) !== 'menu-order-v1') {
            throw ValidationException::withMessages(['group.version' => __('The menu cart version is not supported.')]);
        }

        $serviceDate = trim((string) ($group['service_date'] ?? ''));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $serviceDate)
            || CarbonImmutable::createFromFormat('!Y-m-d', $serviceDate)?->format('Y-m-d') !== $serviceDate) {
            throw ValidationException::withMessages(['group.service_date' => __('Choose a valid service date.')]);
        }

        $items = $group['items'] ?? null;
        if (! is_array($items) || $items === []) {
            throw ValidationException::withMessages(['group.items' => __('Select at least one menu item.')]);
        }

        $normalizedItems = [];
        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages(['group.items' => __('Each menu item must be valid.')]);
            }
            $this->assertExactKeys($item, ['menu_item_id', 'quantity'], 'group.items.'.$index);
            $menuItemId = filter_var($item['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = trim((string) ($item['quantity'] ?? ''));
            if ($menuItemId === false || $menuItemId <= 0 || ! preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $quantity)) {
                throw ValidationException::withMessages(['group.items.'.$index => __('The menu item selection is invalid.')]);
            }
            if (isset($normalizedItems[$menuItemId])) {
                throw ValidationException::withMessages(['group.items' => __('Each menu item can appear once.')]);
            }
            $quantityMilli = MinorUnits::parseQtyMilli($quantity);
            if ($quantityMilli <= 0) {
                throw ValidationException::withMessages(['group.items.'.$index.'.quantity' => __('Quantity must be greater than zero.')]);
            }
            $normalizedItems[$menuItemId] = [
                'menu_item_id' => (int) $menuItemId,
                'quantity' => MinorUnits::format($quantityMilli, 1000),
            ];
        }
        ksort($normalizedItems);
        $normalizedAddOns = $this->normalizeAdditionalItems($group['add_ons'] ?? [], array_keys($normalizedItems));

        $note = array_key_exists('note', $group) ? trim((string) $group['note']) : null;
        $note = $note === '' ? null : $note;
        if ($note !== null && mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['group.note' => __('The order note may not exceed 500 characters.')]);
        }

        return [
            'version' => 'menu-order-v1',
            'service_date' => $serviceDate,
            'items' => array_values($normalizedItems),
            'add_ons' => $normalizedAddOns,
            'note' => $note,
        ];
    }

    /**
     * @param  array<int,int>  $baseIds
     * @return array<int,array{menu_item_id:int,quantity:string}>
     */
    private function normalizeAdditionalItems(mixed $values, array $baseIds): array
    {
        if ($values === null) {
            return [];
        }
        if (! is_array($values)) {
            throw ValidationException::withMessages(['group.add_ons' => __('Checkout add-ons must be valid.')]);
        }
        $normalized = [];
        foreach (array_values($values) as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages(['group.add_ons' => __('Each checkout add-on must be valid.')]);
            }
            $this->assertExactKeys($item, ['menu_item_id', 'quantity'], 'group.add_ons.'.$index);
            $id = filter_var($item['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = trim((string) ($item['quantity'] ?? ''));
            if ($id === false || $id <= 0 || in_array((int) $id, $baseIds, true) || isset($normalized[$id])
                || ! preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $quantity)) {
                throw ValidationException::withMessages(['group.add_ons.'.$index => __('The checkout add-on selection is invalid.')]);
            }
            $quantityMilli = MinorUnits::parseQtyMilli($quantity);
            if ($quantityMilli <= 0) {
                throw ValidationException::withMessages(['group.add_ons.'.$index.'.quantity' => __('Quantity must be greater than zero.')]);
            }
            $normalized[$id] = ['menu_item_id' => (int) $id, 'quantity' => MinorUnits::format($quantityMilli, 1000)];
        }
        ksort($normalized);

        return array_values($normalized);
    }

    public function publicQuote(array $quote): array
    {
        return array_diff_key($quote, array_flip(['_context', '_resolved_values']));
    }

    private function quantityIsValid(string $quantity, StorefrontItemProfile $profile): bool
    {
        $value = MinorUnits::parseQtyMilli($quantity);
        $minimum = MinorUnits::parseQtyMilli((string) $profile->minimum_quantity);
        $increment = MinorUnits::parseQtyMilli((string) $profile->quantity_increment);
        $maximum = $profile->maximum_quantity !== null
            ? MinorUnits::parseQtyMilli((string) $profile->maximum_quantity)
            : null;

        return $value >= $minimum
            && ($maximum === null || $value <= $maximum)
            && $increment > 0
            && ($value - $minimum) % $increment === 0;
    }

    /** @param array<string, mixed> $value
     * @param  array<int, string>  $allowed
     */
    private function assertExactKeys(array $value, array $allowed, string $attribute): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) {
            throw ValidationException::withMessages([
                $attribute => __('Unsupported menu checkout fields were submitted.'),
            ]);
        }
    }
}

<?php

namespace App\Services\Payments;

use App\Models\Branch;
use App\Models\DailyDishMenu;
use App\Models\PaymentSetting;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use App\Services\Customers\CustomerIdentityResolver;
use App\Services\Storefront\StorefrontUpsellService;
use App\Support\Money\MinorUnits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OrdinaryOrderQuoteService
{
    private const MAIN_ROLES = ['main', 'diet', 'vegetarian'];

    public function __construct(
        private readonly AccountingContextService $accountingContext,
        private readonly CustomerIdentityResolver $identityResolver,
        private readonly PaymentTermsService $paymentTerms,
        private readonly CheckoutCanonicalizer $canonicalizer,
        private readonly StorefrontUpsellService $upsells,
    ) {}

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function quote(User $user, array $request): array
    {
        $user = $this->identityResolver->resolveForCheckout($user);
        $context = $this->resolveContext();
        $cart = $request['cart'] ?? [];
        $normalized = $this->normalizeCart(is_array($cart) ? $cart : []);
        $today = now(PaymentSetting::TIMEZONE)->toDateString();

        $excludedToday = [];
        $future = [];
        foreach ($normalized as $day) {
            if ($day['date'] < $today) {
                throw ValidationException::withMessages([
                    'cart.items' => __('Past service dates cannot be ordered online.'),
                ]);
            }

            if ($day['date'] === $today) {
                $excludedToday[] = $this->submissionDay($day);

                continue;
            }

            $future[] = $day;
        }

        if ($future === []) {
            return [
                'cart' => ['items' => []],
                'excluded_today' => $excludedToday,
                'day_totals' => [],
                'gross_amount_cents' => 0,
                'discount_amount_cents' => 0,
                'payable_amount_cents' => 0,
                'currency' => 'QAR',
                'quote_fingerprint' => null,
                'terms_version' => $context['terms']['version'],
                'terms_url' => $context['terms']['url'],
                'terms_content_hash' => $context['terms']['content_hash'],
                'support_phone' => $context['settings']->order_support_phone,
                'can_checkout' => false,
                '_context' => $context,
                '_priced_days' => [],
                '_pricing_version' => $this->pricingVersion(),
                '_canonical_days' => [],
            ];
        }

        $pricedDays = collect($future)
            ->map(fn (array $day): array => $this->priceDay(
                $day,
                (int) $context['company_id'],
                (int) $context['branch']->id,
            ))
            ->sortBy('date')
            ->values()
            ->all();

        $payable = array_sum(array_column($pricedDays, 'total_cents'));
        $addOnTotal = array_sum(array_column($pricedDays, 'add_on_total_cents'));
        if ($payable <= 0 || $payable > PHP_INT_MAX) {
            throw ValidationException::withMessages([
                'cart.items' => __('The selected order has an invalid total.'),
            ]);
        }

        $canonicalDays = array_map(fn (array $day): array => $day['canonical_tuple'], $pricedDays);
        $pricingVersion = $this->pricingVersion();
        $quoteFingerprint = $this->canonicalizer->hash([
            'ordinary-quote-v1',
            (string) $user->customer_id,
            (string) $context['company_id'],
            (string) $context['branch']->id,
            'QAR',
            $canonicalDays,
            $pricingVersion,
            array_column($pricedDays, 'total_cents'),
            $payable,
            $context['terms']['version'],
            $context['terms']['content_hash'],
        ]);

        return [
            'cart' => ['items' => array_map(fn (array $day): array => $day['submission'], $pricedDays)],
            'excluded_today' => $excludedToday,
            'day_totals' => array_map(fn (array $day): array => [
                'date' => $day['date'],
                'total_amount_cents' => $day['total_cents'],
                'items' => $day['display_items'],
            ], $pricedDays),
            'gross_amount_cents' => $payable,
            'discount_amount_cents' => 0,
            'payable_amount_cents' => $payable,
            'add_on_amount_cents' => $addOnTotal,
            'currency' => 'QAR',
            'quote_fingerprint' => $quoteFingerprint,
            'terms_version' => $context['terms']['version'],
            'terms_url' => $context['terms']['url'],
            'terms_content_hash' => $context['terms']['content_hash'],
            'support_phone' => $context['settings']->order_support_phone,
            'can_checkout' => true,
            '_context' => $context,
            '_priced_days' => $pricedDays,
            '_pricing_version' => $pricingVersion,
            '_canonical_days' => $canonicalDays,
        ];
    }

    /**
     * @param  array<string, mixed>  $cart
     * @return array<int, array<string, mixed>>
     */
    public function normalizeCart(array $cart): array
    {
        $this->assertExactKeys($cart, ['items'], 'cart');
        $items = $cart['items'] ?? null;
        if (! is_array($items) || $items === []) {
            throw ValidationException::withMessages([
                'cart.items' => __('Select at least one service date.'),
            ]);
        }

        $days = [];
        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages(['cart.items' => __('Each service date must be valid.')]);
            }
            $this->assertExactKeys($item, ['key', 'mains', 'salad_qty', 'dessert_qty', 'notes', 'add_ons'], 'cart.items.'.$index);

            $date = trim((string) ($item['key'] ?? ''));
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! $this->validDate($date)) {
                throw ValidationException::withMessages(['cart.items.'.$index.'.key' => __('Use a valid service date.')]);
            }
            if (isset($days[$date])) {
                throw ValidationException::withMessages(['cart.items' => __('Each service date can appear once.')]);
            }

            $mains = $item['mains'] ?? null;
            if (! is_array($mains) || $mains === []) {
                throw ValidationException::withMessages(['cart.items.'.$index.'.mains' => __('Select at least one main dish.')]);
            }

            $mergedMains = [];
            foreach (array_values($mains) as $mainIndex => $main) {
                if (! is_array($main)) {
                    throw ValidationException::withMessages(['cart.items.'.$index.'.mains' => __('Each main dish must be valid.')]);
                }
                $this->assertExactKeys($main, ['menu_item_id', 'portion', 'qty'], 'cart.items.'.$index.'.mains.'.$mainIndex);
                $menuItemId = filter_var($main['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
                $portion = strtolower(trim((string) ($main['portion'] ?? '')));
                $qty = filter_var($main['qty'] ?? null, FILTER_VALIDATE_INT);
                if ($menuItemId === false || $menuItemId <= 0 || ! in_array($portion, ['plate', 'half', 'full'], true) || $qty === false || $qty <= 0) {
                    throw ValidationException::withMessages(['cart.items.'.$index.'.mains' => __('Main dish selections are invalid.')]);
                }

                $key = $menuItemId.':'.$portion;
                $mergedMains[$key] = [
                    'menu_item_id' => $menuItemId,
                    'portion' => $portion,
                    'qty' => (int) (($mergedMains[$key]['qty'] ?? 0) + $qty),
                ];
            }

            $saladQty = $this->nonNegativeQuantity($item['salad_qty'] ?? 0, 'cart.items.'.$index.'.salad_qty');
            $dessertQty = $this->nonNegativeQuantity($item['dessert_qty'] ?? 0, 'cart.items.'.$index.'.dessert_qty');
            $notes = array_key_exists('notes', $item) ? trim((string) $item['notes']) : null;
            if ($notes === '') {
                $notes = null;
            }
            if ($notes !== null && mb_strlen($notes) > 2000) {
                throw ValidationException::withMessages(['cart.items.'.$index.'.notes' => __('Notes may not exceed 2000 characters.')]);
            }

            $sortedMains = array_values($mergedMains);
            usort($sortedMains, fn (array $left, array $right): int => [$left['menu_item_id'], $left['portion']] <=> [$right['menu_item_id'], $right['portion']]);
            if (array_key_exists('add_ons', $item) && ! is_array($item['add_ons'])) {
                throw ValidationException::withMessages(['cart.items.'.$index.'.add_ons' => __('Checkout add-ons must be valid.')]);
            }
            $days[$date] = [
                'date' => $date,
                'mains' => $sortedMains,
                'salad_qty' => $saladQty,
                'dessert_qty' => $dessertQty,
                'notes' => $notes,
                'add_ons' => $this->upsells->normalizeSelections(
                    $item['add_ons'] ?? [],
                ),
            ];
        }

        ksort($days);

        return array_values($days);
    }

    /**
     * @return array{company_id:int,branch:Branch,source:PaymentSource,settings:PaymentSetting,terms:array<string,string>}
     */
    private function resolveContext(): array
    {
        $companyId = $this->accountingContext->defaultCompanyId();
        $branch = Branch::query()->find((int) config('payments.public_order_branch_id', 1));
        $settings = $companyId ? PaymentSetting::query()->where('company_id', $companyId)->first() : null;
        $source = $companyId
            ? PaymentSource::query()
                ->where('company_id', $companyId)
                ->where('code', PaymentSource::CODE_SKIPCASH)
                ->where('method', PaymentSource::METHOD_SKIPCASH)
                ->where('is_active', true)
                ->first()
            : null;
        $terms = $this->paymentTerms->inspect()['current'];

        if (! $companyId || ! $branch || ! $branch->is_active || (int) $branch->company_id !== $companyId || ! $settings || ! $source || ! $terms) {
            throw new PaymentCheckoutException('PAYMENT_CONTEXT_UNAVAILABLE', 503, __('Checkout is currently unavailable.'));
        }

        return [
            'company_id' => $companyId,
            'branch' => $branch,
            'source' => $source,
            'settings' => $settings,
            'terms' => $terms,
        ];
    }

    /** @param array<string, mixed> $day
     * @return array<string, mixed>
     */
    private function priceDay(array $day, int $companyId, int $branchId): array
    {
        $menu = DailyDishMenu::query()
            ->with('items.menuItem')
            ->where('branch_id', $branchId)
            ->whereDate('service_date', $day['date'])
            ->where('status', 'published')
            ->first();
        if (! $menu) {
            throw ValidationException::withMessages(['cart.items' => __('No published menu is available for '.$day['date'].'.')]);
        }

        $items = $menu->items->keyBy('menu_item_id');
        $resolvedMains = [];
        foreach ($day['mains'] as $main) {
            $menuRow = $items->get($main['menu_item_id']);
            if (! $menuRow || ! in_array((string) $menuRow->role, self::MAIN_ROLES, true) || ! $menuRow->menuItem) {
                throw ValidationException::withMessages(['cart.items' => __('A selected main dish is unavailable for '.$day['date'].'.')]);
            }
            $resolvedMains[] = $main + [
                'role' => (string) $menuRow->role,
                'name' => (string) $menuRow->menuItem->name,
                'code' => (string) ($menuRow->menuItem->code ?? ''),
            ];
        }

        $salad = $this->resolveUniqueSide($menu->items, 'salad', (int) $day['salad_qty'], $day['date']);
        $dessert = $this->resolveUniqueSide($menu->items, 'dessert', (int) $day['dessert_qty'], $day['date']);
        $upsell = $this->upsells->quoteDate(
            $day['date'],
            $day['add_ons'],
            false,
            $companyId,
            $branchId,
        );
        $orderLines = [];
        $displayItems = [];
        $allPlate = collect($resolvedMains)->every(fn (array $main): bool => $main['portion'] === 'plate');

        if ($allPlate) {
            $remainingSalad = (int) $day['salad_qty'];
            $remainingDessert = (int) $day['dessert_qty'];
            foreach ($resolvedMains as $main) {
                for ($unit = 0; $unit < $main['qty']; $unit++) {
                    $hasSalad = $remainingSalad > 0;
                    $hasDessert = $remainingDessert > 0;
                    $unitPrice = $this->bundlePriceCents($hasSalad, $hasDessert);
                    $orderLines[] = $this->orderLine($main, 1, $unitPrice);
                    $displayItems[] = ['menu_item_id' => $main['menu_item_id'], 'role' => $main['role'], 'quantity' => 1, 'amount_cents' => $unitPrice];
                    if ($hasSalad) {
                        $remainingSalad--;
                    }
                    if ($hasDessert) {
                        $remainingDessert--;
                    }
                }
            }
            if ($salad) {
                $orderLines[] = $this->orderLine($salad, (int) $day['salad_qty'] - $remainingSalad, 0);
                if ($remainingSalad > 0) {
                    $orderLines[] = $this->orderLine($salad, $remainingSalad, $this->addonPriceCents('salad'));
                }
            }
            if ($dessert) {
                $orderLines[] = $this->orderLine($dessert, (int) $day['dessert_qty'] - $remainingDessert, 0);
                if ($remainingDessert > 0) {
                    $orderLines[] = $this->orderLine($dessert, $remainingDessert, $this->addonPriceCents('dessert'));
                }
            }
        } else {
            foreach ($resolvedMains as $main) {
                $price = $this->portionPriceCents($main['portion']);
                $orderLines[] = $this->orderLine($main, (int) $main['qty'], $price);
                $displayItems[] = ['menu_item_id' => $main['menu_item_id'], 'role' => $main['role'], 'quantity' => (int) $main['qty'], 'amount_cents' => $price * (int) $main['qty']];
            }
            if ($salad) {
                $orderLines[] = $this->orderLine($salad, (int) $day['salad_qty'], $this->addonPriceCents('salad'));
            }
            if ($dessert) {
                $orderLines[] = $this->orderLine($dessert, (int) $day['dessert_qty'], $this->addonPriceCents('dessert'));
            }
        }

        foreach ($upsell['lines'] as $line) {
            $orderLines[] = $line;
            $displayItems[] = [
                'menu_item_id' => $line['menu_item_id'],
                'role' => 'checkout_add_on',
                'quantity' => $line['quantity'],
                'amount_cents' => $line['line_total_cents'],
            ];
        }
        $orderLines = array_values(array_filter($orderLines, fn (array $line): bool => (float) $line['quantity'] > 0));
        $total = array_sum(array_column($orderLines, 'line_total_cents'));
        $submission = $this->submissionDay($day);
        $canonicalTuple = [
            $day['date'],
            array_map(fn (array $main): array => [(string) $main['menu_item_id'], $main['portion'], (int) $main['qty']], $resolvedMains),
            $salad['menu_item_id'] ?? null,
            (int) $day['salad_qty'],
            $dessert['menu_item_id'] ?? null,
            (int) $day['dessert_qty'],
            $day['notes'],
            $upsell['canonical'],
        ];

        return [
            'date' => $day['date'],
            'submission' => $submission,
            'canonical_tuple' => $canonicalTuple,
            'total_cents' => $total,
            'order_lines' => $orderLines,
            'display_items' => $displayItems,
            'notes' => $day['notes'],
            'add_on_total_cents' => $upsell['total_cents'],
        ];
    }

    /** @param Collection<int, \App\Models\DailyDishMenuItem> $menuItems
     * @return array<string, mixed>|null
     */
    private function resolveUniqueSide(Collection $menuItems, string $role, int $quantity, string $date): ?array
    {
        if ($quantity === 0) {
            return null;
        }
        $matches = $menuItems->filter(fn ($item): bool => $item->role === $role && $item->menuItem !== null)->values();
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['cart.items' => __('The '.$role.' selection for '.$date.' needs one configured menu item.')]);
        }
        $row = $matches->first();

        return [
            'menu_item_id' => (int) $row->menu_item_id,
            'role' => $role,
            'name' => (string) $row->menuItem->name,
            'code' => (string) ($row->menuItem->code ?? ''),
        ];
    }

    /** @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function orderLine(array $item, int $quantity, int $unitPriceCents): array
    {
        return [
            'menu_item_id' => (int) $item['menu_item_id'],
            'role' => (string) $item['role'],
            'description' => trim('Daily Dish ('.$item['role'].')'.(($item['code'] ?? '') !== '' ? ' '.$item['code'] : '').' '.$item['name']),
            'quantity' => $quantity,
            'unit_price_cents' => $unitPriceCents,
            'line_total_cents' => $quantity * $unitPriceCents,
        ];
    }

    private function bundlePriceCents(bool $salad, bool $dessert): int
    {
        $key = $salad && $dessert ? 'main_plus_both' : ($salad || $dessert ? 'main_plus_one' : 'main_only');

        return $this->priceCents('pricing.meal_plan.base_prices.'.$key);
    }

    private function portionPriceCents(string $portion): int
    {
        return $this->priceCents('pricing.daily_dish.portion_prices.'.$portion);
    }

    private function addonPriceCents(string $addon): int
    {
        return $this->priceCents('pricing.daily_dish.addon_prices.'.$addon);
    }

    private function priceCents(string $key): int
    {
        $value = config($key);
        if (! is_numeric($value)) {
            throw new PaymentCheckoutException('PRICING_UNAVAILABLE', 503, __('Checkout pricing is unavailable.'));
        }

        return MinorUnits::parse((string) $value, (int) config('payments.money_scale', 100));
    }

    private function pricingVersion(): string
    {
        $keys = [
            'pricing.daily_dish.addon_prices.dessert',
            'pricing.daily_dish.addon_prices.salad',
            'pricing.daily_dish.portion_prices.full',
            'pricing.daily_dish.portion_prices.half',
            'pricing.daily_dish.portion_prices.plate',
            'pricing.meal_plan.base_prices.main_only',
            'pricing.meal_plan.base_prices.main_plus_both',
            'pricing.meal_plan.base_prices.main_plus_one',
        ];
        sort($keys, SORT_STRING);
        $values = [];
        foreach ($keys as $key) {
            $values[] = [$key, $this->priceCents($key)];
        }

        return $this->canonicalizer->hash(['ordinary-pricing-v1', $values]);
    }

    /** @param array<string, mixed> $day
     * @return array<string, mixed>
     */
    private function submissionDay(array $day): array
    {
        return [
            'key' => $day['date'],
            'mains' => array_map(fn (array $main): array => [
                'menu_item_id' => (int) $main['menu_item_id'],
                'portion' => $main['portion'],
                'qty' => (int) $main['qty'],
            ], $day['mains']),
            'salad_qty' => (int) $day['salad_qty'],
            'dessert_qty' => (int) $day['dessert_qty'],
            'notes' => $day['notes'],
            'add_ons' => $day['add_ons'],
        ];
    }

    /** @param array<string, mixed> $value
     * @param  array<int, string>  $allowed
     */
    private function assertExactKeys(array $value, array $allowed, string $attribute): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                $attribute => __('Unsupported checkout fields were submitted.'),
            ]);
        }
    }

    private function nonNegativeQuantity(mixed $value, string $attribute): int
    {
        $quantity = filter_var($value, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 0) {
            throw ValidationException::withMessages([$attribute => __('Quantity must be a nonnegative whole number.')]);
        }

        return $quantity;
    }

    private function validDate(string $value): bool
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, PaymentSetting::TIMEZONE);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}

<?php

namespace App\Services\Storefront;

use App\Models\StorefrontCategory;
use App\Models\StorefrontItemProfile;
use App\Services\Payments\PaymentCheckoutException;
use App\Support\Money\MinorUnits;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class StorefrontUpsellService
{
    private const MAX_SAFE_CENTS = 9007199254740991;

    public function __construct(
        private readonly StorefrontContextService $contexts,
        private readonly StorefrontCatalogService $catalog,
        private readonly StorefrontAvailabilityService $availability,
    ) {}

    /** @param array<int, string> $dates
     * @return array<string, mixed>
     */
    public function publicItems(array $dates, string $pathCode = 'daily_dish'): array
    {
        try {
            $branchId = $pathCode === 'advance_menu'
                ? null
                : (int) config('payments.public_order_branch_id', 1);
            $context = $this->contexts->checkoutUpsell(checkoutBranchId: $branchId);
        } catch (PaymentCheckoutException $exception) {
            if ($exception->codeName === 'CHECKOUT_UPSELL_DISABLED') {
                return ['enabled' => false, 'category' => null, 'dates' => []];
            }
            throw $exception;
        }
        $dates = $this->normalizeDates($dates);
        if ($dates === []) {
            return ['enabled' => true, 'category' => null, 'dates' => []];
        }
        $category = StorefrontCategory::query()
            ->where('company_id', $context['company_id'])
            ->where('is_active', true)
            ->find($context['storefront']->upsell_category_id);
        if (! $category) {
            return ['enabled' => true, 'category' => null, 'dates' => []];
        }
        $profiles = $this->eligibleProfiles($context)->get();
        $byDate = [];
        foreach ($dates as $date) {
            if ($this->availability->isClosedDate($context['storefront'], $date)) {
                $byDate[] = ['service_date' => $date, 'items' => []];

                continue;
            }
            $items = $profiles
                ->filter(fn (StorefrontItemProfile $profile): bool => $this->availability
                    ->earliestDate($context['storefront'], $profile) <= $date)
                ->map(fn (StorefrontItemProfile $profile): array => $this->catalog
                    ->present($profile, $context['storefront']))
                ->values()
                ->all();
            $byDate[] = ['service_date' => $date, 'items' => $items];
        }

        return [
            'enabled' => collect($byDate)->contains(fn (array $day): bool => $day['items'] !== []),
            'category' => ['id' => (int) $category->id, 'title' => (string) $category->title],
            'currency' => 'QAR',
            'dates' => $byDate,
        ];
    }

    /** @param array<int, mixed> $selections
     * @return array{lines:array<int,array<string,mixed>>,total_cents:int,canonical:array<int,array<string,string|int>>}
     */
    public function quoteDate(
        string $serviceDate,
        array $selections,
        bool $lockForUpdate = false,
        ?int $companyId = null,
        ?int $branchId = null,
    ): array {
        if ($selections === []) {
            return ['lines' => [], 'total_cents' => 0, 'canonical' => []];
        }
        $serviceDate = $this->normalizeDates([$serviceDate])[0] ?? '';
        $context = $this->contexts->checkoutUpsell($lockForUpdate, $companyId, $branchId);
        if ($this->availability->isClosedDate($context['storefront'], $serviceDate, $lockForUpdate)) {
            throw new PaymentCheckoutException('UPSELL_SERVICE_DATE_CLOSED', 422, __('Add-ons are unavailable for this service date.'));
        }
        $normalized = $this->normalizeSelections($selections);
        $profiles = $this->eligibleProfiles($context)
            ->whereIn('menu_item_id', array_column($normalized, 'menu_item_id'))
            ->orderBy('menu_item_id')
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->get()
            ->keyBy('menu_item_id');
        if ($profiles->count() !== count($normalized)) {
            throw new PaymentCheckoutException('UPSELL_ITEM_UNAVAILABLE', 409, __('One or more checkout add-ons are no longer available.'));
        }

        $lines = [];
        foreach ($normalized as $selection) {
            $profile = $profiles->get($selection['menu_item_id']);
            if (! $profile || $this->availability->earliestDate($context['storefront'], $profile) > $serviceDate
                || ! $this->quantityIsValid($selection['quantity'], $profile)) {
                throw new PaymentCheckoutException('UPSELL_ITEM_UNAVAILABLE', 409, __('One or more checkout add-ons are unavailable for the selected date or quantity.'));
            }
            $unitCents = MinorUnits::parse((string) $profile->menuItem->selling_price_per_unit, 100);
            $quantityMilli = MinorUnits::parseQtyMilli($selection['quantity']);
            if ($unitCents <= 0 || $quantityMilli <= 0 || $unitCents > intdiv(PHP_INT_MAX, $quantityMilli)) {
                throw ValidationException::withMessages(['add_ons' => __('The checkout add-on total is invalid.')]);
            }
            $lineCents = MinorUnits::mulQty($unitCents, $quantityMilli);
            if ($lineCents <= 0 || $lineCents > self::MAX_SAFE_CENTS) {
                throw ValidationException::withMessages(['add_ons' => __('The checkout add-on total is invalid.')]);
            }
            $lines[] = [
                'role' => 'checkout_add_on',
                'profile_id' => (int) $profile->id,
                'menu_item_id' => (int) $profile->menu_item_id,
                'description' => trim((string) $profile->customer_title) !== ''
                    ? (string) $profile->customer_title
                    : (string) $profile->menuItem->name,
                'unit' => (string) $profile->menuItem->unit,
                'quantity' => $selection['quantity'],
                'unit_price_cents' => $unitCents,
                'line_total_cents' => $lineCents,
            ];
        }
        $total = array_sum(array_column($lines, 'line_total_cents'));
        if ($total <= 0 || $total > self::MAX_SAFE_CENTS) {
            throw ValidationException::withMessages(['add_ons' => __('The checkout add-on total is invalid.')]);
        }

        return [
            'lines' => $lines,
            'total_cents' => $total,
            'canonical' => array_map(fn (array $line): array => [
                'menu_item_id' => $line['menu_item_id'],
                'quantity' => $line['quantity'],
                'unit_price_cents' => $line['unit_price_cents'],
                'line_total_cents' => $line['line_total_cents'],
            ], $lines),
        ];
    }

    /** @param array<string,mixed> $context */
    private function eligibleProfiles(array $context)
    {
        return $this->catalog
            ->eligibleQuery((int) $context['company_id'], (int) $context['branch']->id)
            ->where('category_id', (int) $context['storefront']->upsell_category_id)
            ->orderBy('display_order')
            ->orderBy('id');
    }

    /** @param array<int, mixed> $values
     * @return array<int, array{menu_item_id:int,quantity:string}>
     */
    public function normalizeSelections(array $values): array
    {
        $result = [];
        foreach (array_values($values) as $index => $value) {
            if (! is_array($value) || array_diff(array_keys($value), ['menu_item_id', 'quantity']) !== []) {
                throw ValidationException::withMessages(['add_ons.'.$index => __('The checkout add-on is invalid.')]);
            }
            $id = filter_var($value['menu_item_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = trim((string) ($value['quantity'] ?? ''));
            if ($id === false || $id <= 0 || ! preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $quantity)) {
                throw ValidationException::withMessages(['add_ons.'.$index => __('The checkout add-on is invalid.')]);
            }
            if (isset($result[$id])) {
                throw ValidationException::withMessages(['add_ons' => __('Each checkout add-on may appear once per service date.')]);
            }
            $result[$id] = ['menu_item_id' => (int) $id, 'quantity' => MinorUnits::format(MinorUnits::parseQtyMilli($quantity), 1000)];
        }
        ksort($result);

        return array_values($result);
    }

    /** @param array<int, mixed> $dates
     * @return array<int, string>
     */
    private function normalizeDates(array $dates): array
    {
        $result = [];
        foreach ($dates as $date) {
            $date = trim((string) $date);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                || CarbonImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date) {
                throw ValidationException::withMessages(['service_dates' => __('Choose valid service dates for checkout add-ons.')]);
            }
            $result[$date] = $date;
        }
        ksort($result);

        return array_values($result);
    }

    private function quantityIsValid(string $quantity, StorefrontItemProfile $profile): bool
    {
        $value = MinorUnits::parseQtyMilli($quantity);
        $minimum = MinorUnits::parseQtyMilli((string) $profile->minimum_quantity);
        $increment = MinorUnits::parseQtyMilli((string) $profile->quantity_increment);
        $maximum = $profile->maximum_quantity !== null ? MinorUnits::parseQtyMilli((string) $profile->maximum_quantity) : null;

        return $value >= $minimum && ($maximum === null || $value <= $maximum)
            && $increment > 0 && ($value - $minimum) % $increment === 0;
    }
}

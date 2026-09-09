<?php

namespace App\Services\Storefront;

use App\Models\MenuItem;
use App\Models\StorefrontDeliveryChannel;
use App\Models\StorefrontItemProfile;
use App\Models\StorefrontSetting;
use App\Services\Payments\PaymentCheckoutException;
use App\Support\Money\MinorUnits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StorefrontCatalogService
{
    public function __construct(
        private readonly StorefrontContextService $contexts,
        private readonly StorefrontAvailabilityService $availability,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginateDirectItems(?int $categoryId, ?string $search, int $perPage = 24): LengthAwarePaginator
    {
        $context = $this->contexts->directMenu();
        $query = $this->eligibleQuery((int) $context['company_id'], (int) $context['branch']->id)
            ->when($categoryId, fn (Builder $builder): Builder => $builder->where('category_id', $categoryId))
            ->when(trim((string) $search) !== '', function (Builder $builder) use ($search): void {
                $like = '%'.trim((string) $search).'%';
                $builder->where(function (Builder $searchQuery) use ($like): void {
                    $searchQuery->where('customer_title', 'like', $like)
                        ->orWhere('short_description', 'like', $like)
                        ->orWhereHas('category', fn (Builder $category): Builder => $category->where('title', 'like', $like));
                });
            })
            ->orderBy('display_order')
            ->orderByRaw('COALESCE(NULLIF(customer_title, ?), (SELECT name FROM menu_items WHERE menu_items.id = storefront_item_profiles.menu_item_id))', [''])
            ->orderBy('menu_item_id');

        $page = $query->paginate(max(1, min(48, $perPage)));
        $page->setCollection($page->getCollection()
            ->map(fn (StorefrontItemProfile $profile): array => $this->present($profile, $context['storefront'])));

        return $page;
    }

    public function directItem(int $profileId): array
    {
        $context = $this->contexts->directMenu();
        $profile = $this->eligibleQuery((int) $context['company_id'], (int) $context['branch']->id)
            ->whereKey($profileId)
            ->first();
        if (! $profile) {
            throw new PaymentCheckoutException(
                'MENU_ITEM_UNAVAILABLE',
                404,
                __('This menu item is no longer available.'),
            );
        }

        $deliveryLinks = [];
        try {
            foreach ($this->deliveryApplications() as $channel) {
                foreach ($channel['items'] as $item) {
                    if ((int) $item['profile_id'] !== (int) $profile->id) {
                        continue;
                    }
                    $deliveryLinks[] = [
                        'code' => $channel['code'],
                        'label' => $channel['label'],
                        'destination_url' => $item['destination_url'],
                    ];
                }
            }
        } catch (PaymentCheckoutException) {
            $deliveryLinks = [];
        }

        return [
            ...$this->present($profile, $context['storefront']),
            'delivery_app_links' => $deliveryLinks,
        ];
    }

    public function directMenuItem(int $menuItemId): array
    {
        $context = $this->contexts->directMenu();
        $profile = $this->eligibleQuery((int) $context['company_id'], (int) $context['branch']->id)
            ->where('menu_item_id', $menuItemId)
            ->first();
        if (! $profile) {
            throw new PaymentCheckoutException(
                'MENU_ITEM_UNAVAILABLE',
                404,
                __('This menu item is no longer available.'),
            );
        }

        return $this->present($profile, $context['storefront']);
    }

    /**
     * @param  array<int, int>  $menuItemIds
     * @return Collection<int, StorefrontItemProfile>
     */
    public function directProfiles(array $menuItemIds, bool $lockForUpdate = false): Collection
    {
        $context = $this->contexts->directMenu($lockForUpdate);
        $query = $this->eligibleQuery((int) $context['company_id'], (int) $context['branch']->id)
            ->whereIn('menu_item_id', $menuItemIds)
            ->orderBy('menu_item_id');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    public function eligibleQuery(int $companyId, int $branchId): Builder
    {
        return StorefrontItemProfile::query()
            ->select('storefront_item_profiles.*')
            ->with(['menuItem:id,name,arabic_name,selling_price_per_unit,unit,is_active', 'category:id,company_id,title,is_active'])
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('direct_order_enabled', true)
            ->whereHas('category', fn (Builder $category): Builder => $category
                ->where('company_id', $companyId)
                ->where('is_active', true))
            ->whereHas('menuItem', fn (Builder $item): Builder => $item
                ->where('is_active', true)
                ->whereIn('unit', array_keys(MenuItem::unitOptions()))
                ->where('selling_price_per_unit', '>', 0))
            ->whereExists(function ($query) use ($branchId): void {
                $query->selectRaw('1')
                    ->from('menu_item_branches')
                    ->whereColumn('menu_item_branches.menu_item_id', 'storefront_item_profiles.menu_item_id')
                    ->where('menu_item_branches.branch_id', $branchId);
            });
    }

    /** @return array<int, array<string, mixed>> */
    public function deliveryApplications(): array
    {
        $context = $this->contexts->deliveryApps();
        $branchId = (int) $context['branch']->id;
        $channels = StorefrontDeliveryChannel::query()
            ->with(['items' => fn ($query) => $query
                ->where('is_enabled', true)
                ->with(['profile.menuItem'])])
            ->where('company_id', $context['company_id'])
            ->where('is_enabled', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return $channels->map(function (StorefrontDeliveryChannel $channel) use ($context, $branchId): ?array {
            $items = $channel->items->filter(function ($link) use ($channel, $context, $branchId): bool {
                $profile = $link->profile;
                $item = $profile?->menuItem;
                $url = trim((string) ($link->item_url ?: $channel->restaurant_url));

                return $profile
                    && (int) $profile->company_id === (int) $context['company_id']
                    && (int) $profile->branch_id === $branchId
                    && $item?->is_active
                    && $url !== ''
                    && DB::table('menu_item_branches')->where('menu_item_id', $item->id)->where('branch_id', $branchId)->exists();
            })->map(function ($link) use ($channel): array {
                $profile = $link->profile;

                return [
                    'profile_id' => (int) $profile->id,
                    'menu_item_id' => (int) $profile->menu_item_id,
                    'title' => trim((string) $profile->customer_title) !== ''
                        ? (string) $profile->customer_title
                        : (string) $profile->menuItem->name,
                    'description' => $profile->short_description,
                    'image_url' => $this->imageUrl($profile),
                    'destination_url' => (string) ($link->item_url ?: $channel->restaurant_url),
                ];
            })->values();

            if ($items->isEmpty() && blank($channel->restaurant_url)) {
                return null;
            }

            return [
                'code' => (string) $channel->code,
                'label' => (string) $channel->label,
                'restaurant_url' => $channel->restaurant_url,
                'items' => $items,
            ];
        })->filter()->values()->all();
    }

    /** @return array<string, mixed> */
    public function present(StorefrontItemProfile $profile, StorefrontSetting $settings): array
    {
        $item = $profile->menuItem;
        $priceCents = MinorUnits::parse((string) $item->selling_price_per_unit, 100);

        return [
            'id' => (int) $profile->id,
            'menu_item_id' => (int) $profile->menu_item_id,
            'category' => [
                'id' => (int) $profile->category->id,
                'title' => (string) $profile->category->title,
            ],
            'title' => trim((string) $profile->customer_title) !== ''
                ? (string) $profile->customer_title
                : (string) $item->name,
            'arabic_title' => $item->arabic_name,
            'description' => $profile->short_description,
            'image_url' => $this->imageUrl($profile),
            'unit' => (string) $item->unit,
            'unit_price_cents' => $priceCents,
            'minimum_quantity' => (string) $profile->minimum_quantity,
            'quantity_increment' => (string) $profile->quantity_increment,
            'maximum_quantity' => $profile->maximum_quantity !== null
                ? (string) $profile->maximum_quantity
                : null,
            'earliest_service_date' => $this->availability->earliestDate($settings, $profile),
        ];
    }

    private function imageUrl(StorefrontItemProfile $profile): ?string
    {
        if (blank($profile->image_disk) || blank($profile->image_path)) {
            return null;
        }

        try {
            return Storage::disk((string) $profile->image_disk)->url((string) $profile->image_path);
        } catch (Throwable) {
            return null;
        }
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StorefrontCategory;
use App\Services\Payments\PaymentCheckoutException;
use App\Services\Storefront\StorefrontCatalogService;
use App\Services\Storefront\StorefrontContextService;
use App\Services\Storefront\StorefrontDiscoveryService;
use App\Services\Storefront\StorefrontUpsellService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class PublicStorefrontController extends Controller
{
    public function __construct(
        private readonly StorefrontContextService $contexts,
        private readonly StorefrontCatalogService $catalog,
        private readonly StorefrontDiscoveryService $discovery,
        private readonly StorefrontUpsellService $upsells,
    ) {}

    public function show()
    {
        try {
            $context = $this->contexts->directMenu();
            $categoryIds = $this->catalog
                ->eligibleQuery((int) $context['company_id'], (int) $context['branch']->id)
                ->whereNotNull('category_id')
                ->distinct()
                ->pluck('category_id');
            $categories = StorefrontCategory::query()
                ->where('company_id', $context['company_id'])
                ->where('is_active', true)
                ->whereIn('id', $categoryIds)
                ->orderBy('display_order')
                ->orderBy('title')
                ->get(['id', 'slug', 'title', 'description'])
                ->map(fn (StorefrontCategory $category): array => [
                    'id' => (int) $category->id,
                    'slug' => (string) $category->slug,
                    'title' => (string) $category->title,
                    'description' => $category->description,
                ]);

            return response()->json([
                'normal_menu_enabled' => true,
                'currency' => 'QAR',
                'timezone' => (string) $context['storefront']->timezone,
                'qatar_date' => CarbonImmutable::now((string) $context['storefront']->timezone)->toDateString(),
                'cutoff_time' => (string) $context['storefront']->menu_cutoff_time,
                'delivery_included' => true,
                'categories' => $categories,
                'featured' => $this->discovery->featured(
                    (int) $context['company_id'],
                    (int) $context['branch']->id,
                    $context['storefront'],
                ),
            ]);
        } catch (PaymentCheckoutException $exception) {
            return $this->error($exception);
        }
    }

    public function upsellItems(Request $request)
    {
        $payload = $request->validate([
            'service_dates' => ['required', 'array', 'min:1', 'max:31'],
            'service_dates.*' => ['required', 'date_format:Y-m-d'],
            'path_code' => ['nullable', 'in:daily_dish,membership_purchase,membership_booking,advance_menu'],
        ]);

        try {
            return response()->json($this->upsells->publicItems(
                $payload['service_dates'],
                (string) ($payload['path_code'] ?? 'daily_dish'),
            ));
        } catch (PaymentCheckoutException $exception) {
            return $this->error($exception);
        }
    }

    public function items(Request $request)
    {
        $payload = $request->validate([
            'category_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        try {
            return response()->json($this->catalog->paginateDirectItems(
                isset($payload['category_id']) ? (int) $payload['category_id'] : null,
                $payload['search'] ?? null,
                (int) ($payload['per_page'] ?? 24),
            ));
        } catch (PaymentCheckoutException $exception) {
            return $this->error($exception);
        }
    }

    public function item(int $profile)
    {
        try {
            return response()->json($this->catalog->directItem($profile));
        } catch (PaymentCheckoutException $exception) {
            return $this->error($exception);
        }
    }

    public function menuItem(int $menuItem)
    {
        try {
            return response()->json($this->catalog->directMenuItem($menuItem));
        } catch (PaymentCheckoutException $exception) {
            return $this->error($exception);
        }
    }

    public function deliveryApps()
    {
        try {
            return response()->json(['data' => $this->catalog->deliveryApplications()]);
        } catch (PaymentCheckoutException $exception) {
            return $this->error($exception);
        }
    }

    private function error(PaymentCheckoutException $exception)
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeName,
            ...$exception->context,
        ], $exception->status);
    }
}

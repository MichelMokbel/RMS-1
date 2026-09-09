<?php

namespace App\Services\Storefront;

use App\Models\Branch;
use App\Models\PaymentSetting;
use App\Models\PaymentSource;
use App\Models\StorefrontSetting;
use App\Services\Accounting\AccountingContextService;
use App\Services\Payments\PaymentCheckoutException;

class StorefrontContextService
{
    public function __construct(
        private readonly AccountingContextService $accountingContext,
    ) {}

    /**
     * @return array{company_id:int,branch:Branch,storefront:StorefrontSetting,payment_settings:PaymentSetting,source:PaymentSource}
     */
    public function directMenu(bool $lockForUpdate = false): array
    {
        $companyId = $this->accountingContext->defaultCompanyId();
        $storefrontQuery = StorefrontSetting::query()->where('company_id', $companyId);
        if ($lockForUpdate) {
            $storefrontQuery->lockForUpdate();
        }
        $storefront = $companyId ? $storefrontQuery->first() : null;

        if (! $companyId || ! $storefront || ! $storefront->normal_menu_enabled) {
            throw new PaymentCheckoutException(
                'MENU_ORDER_DISABLED',
                404,
                __('Advance menu ordering is currently unavailable.'),
            );
        }

        $branchQuery = Branch::query()->whereKey($storefront->portal_branch_id);
        if ($lockForUpdate) {
            $branchQuery->lockForUpdate();
        }
        $branch = $branchQuery->first();
        $paymentSettings = PaymentSetting::query()->where('company_id', $companyId)->first();
        $source = PaymentSource::query()
            ->where('company_id', $companyId)
            ->where('code', PaymentSource::CODE_SKIPCASH)
            ->where('method', PaymentSource::METHOD_SKIPCASH)
            ->where('is_active', true)
            ->first();

        if (! $branch || ! $branch->is_active || (int) $branch->company_id !== $companyId
            || ! $paymentSettings || ! $source) {
            throw new PaymentCheckoutException(
                'STOREFRONT_CONTEXT_UNAVAILABLE',
                503,
                __('Advance menu ordering is currently unavailable.'),
            );
        }

        return [
            'company_id' => $companyId,
            'branch' => $branch,
            'storefront' => $storefront,
            'payment_settings' => $paymentSettings,
            'source' => $source,
        ];
    }

    /** @return array{company_id:int,branch:Branch,storefront:StorefrontSetting} */
    public function checkoutUpsell(
        bool $lockForUpdate = false,
        ?int $checkoutCompanyId = null,
        ?int $checkoutBranchId = null,
    ): array {
        $companyId = (int) $this->accountingContext->defaultCompanyId();
        if ($checkoutCompanyId !== null && $checkoutCompanyId !== $companyId) {
            throw new PaymentCheckoutException(
                'STOREFRONT_CONTEXT_UNAVAILABLE',
                503,
                __('Checkout add-ons are currently unavailable.'),
            );
        }
        $settingsQuery = StorefrontSetting::query()->where('company_id', $companyId);
        if ($lockForUpdate) {
            $settingsQuery->lockForUpdate();
        }
        $storefront = $companyId > 0 ? $settingsQuery->first() : null;
        if (! $storefront || ! $storefront->checkout_upsell_enabled || ! $storefront->upsell_category_id) {
            throw new PaymentCheckoutException(
                'CHECKOUT_UPSELL_DISABLED',
                404,
                __('Checkout add-ons are currently unavailable.'),
            );
        }

        $branchQuery = Branch::query()->whereKey($checkoutBranchId ?? $storefront->portal_branch_id);
        if ($lockForUpdate) {
            $branchQuery->lockForUpdate();
        }
        $branch = $branchQuery->first();
        if (! $branch || ! $branch->is_active || (int) $branch->company_id !== $companyId) {
            throw new PaymentCheckoutException(
                'STOREFRONT_CONTEXT_UNAVAILABLE',
                503,
                __('Checkout add-ons are currently unavailable.'),
            );
        }

        return ['company_id' => $companyId, 'branch' => $branch, 'storefront' => $storefront];
    }

    /** @return array{company_id:int,branch:Branch,storefront:StorefrontSetting} */
    public function deliveryApps(): array
    {
        $companyId = (int) $this->accountingContext->defaultCompanyId();
        $storefront = $companyId > 0
            ? StorefrontSetting::query()->where('company_id', $companyId)->first()
            : null;
        if (! $storefront || ! $storefront->delivery_apps_enabled) {
            throw new PaymentCheckoutException(
                'DELIVERY_APPS_DISABLED',
                404,
                __('Delivery application ordering is currently unavailable.'),
            );
        }
        $branch = Branch::query()->whereKey($storefront->portal_branch_id)->first();
        if (! $branch || ! $branch->is_active || (int) $branch->company_id !== $companyId) {
            throw new PaymentCheckoutException(
                'STOREFRONT_CONTEXT_UNAVAILABLE',
                503,
                __('Delivery application ordering is currently unavailable.'),
            );
        }

        return ['company_id' => $companyId, 'branch' => $branch, 'storefront' => $storefront];
    }
}

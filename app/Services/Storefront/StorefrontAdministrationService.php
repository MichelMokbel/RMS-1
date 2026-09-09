<?php

namespace App\Services\Storefront;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\StorefrontCategory;
use App\Models\StorefrontClosedDate;
use App\Models\StorefrontDeliveryChannel;
use App\Models\StorefrontItemChannel;
use App\Models\StorefrontItemProfile;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Menu\MenuItemUsageService;
use App\Support\Money\MinorUnits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StorefrontAdministrationService
{
    public function __construct(
        private readonly AccountingContextService $accountingContext,
        private readonly AccountingAuditLogService $auditLog,
        private readonly MenuItemUsageService $menuItemUsage,
    ) {}

    public function companyIdFor(User $actor): int
    {
        $companyId = (int) $this->accountingContext->defaultCompanyId();
        if ($companyId <= 0 || ! $actor->hasRole('admin') || ! $actor->can('storefront.manage')) {
            abort(403);
        }

        return $companyId;
    }

    public function settings(int $companyId): ?StorefrontSetting
    {
        return StorefrontSetting::query()->where('company_id', $companyId)->first();
    }

    /** @param array<string, mixed> $data */
    public function saveSettings(User $actor, array $data, int $expectedRevision): StorefrontSetting
    {
        $companyId = $this->companyIdFor($actor);
        $branchId = filter_var($data['portal_branch_id'] ?? null, FILTER_VALIDATE_INT);
        $cutoff = trim((string) ($data['menu_cutoff_time'] ?? ''));
        if ($branchId === false || $branchId <= 0) {
            throw ValidationException::withMessages(['portal_branch_id' => __('Choose an active portal branch.')]);
        }
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $cutoff)) {
            throw ValidationException::withMessages(['menu_cutoff_time' => __('The menu cutoff must use HH:MM in Qatar time.')]);
        }

        return DB::transaction(function () use ($actor, $companyId, $branchId, $cutoff, $data, $expectedRevision): StorefrontSetting {
            $branch = Branch::query()->whereKey($branchId)->lockForUpdate()->first();
            if (! $branch || ! $branch->is_active || (int) $branch->company_id !== $companyId) {
                throw ValidationException::withMessages(['portal_branch_id' => __('Choose an active branch from the default company.')]);
            }
            $settings = StorefrontSetting::query()->where('company_id', $companyId)->lockForUpdate()->first();
            $this->assertRevision($settings, $expectedRevision);
            $before = $settings?->only([
                'portal_branch_id', 'normal_menu_enabled', 'checkout_upsell_enabled', 'upsell_category_id',
                'menu_cutoff_time', 'timezone', 'delivery_apps_enabled', 'revision',
            ]);
            $upsellCategoryId = filter_var($data['upsell_category_id'] ?? null, FILTER_VALIDATE_INT);
            if ($upsellCategoryId === false || $upsellCategoryId <= 0) {
                $upsellCategoryId = null;
            }
            if ($upsellCategoryId !== null && ! StorefrontCategory::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->whereKey($upsellCategoryId)
                ->exists()) {
                throw ValidationException::withMessages([
                    'upsell_category_id' => __('Choose an active storefront category for checkout add-ons.'),
                ]);
            }
            if ((bool) ($data['checkout_upsell_enabled'] ?? false) && $upsellCategoryId === null) {
                throw ValidationException::withMessages([
                    'upsell_category_id' => __('Choose the storefront category before enabling checkout add-ons.'),
                ]);
            }
            $settings ??= new StorefrontSetting([
                'company_id' => $companyId,
                'created_by' => $actor->id,
                'revision' => 0,
            ]);
            $settings->fill([
                'portal_branch_id' => $branch->id,
                'normal_menu_enabled' => (bool) ($data['normal_menu_enabled'] ?? false),
                'checkout_upsell_enabled' => (bool) ($data['checkout_upsell_enabled'] ?? false),
                'upsell_category_id' => $upsellCategoryId,
                'menu_cutoff_time' => $cutoff.':00',
                'timezone' => StorefrontSetting::TIMEZONE,
                'delivery_apps_enabled' => (bool) ($data['delivery_apps_enabled'] ?? false),
                'revision' => $expectedRevision + 1,
                'updated_by' => $actor->id,
            ])->save();
            $this->audit('storefront.settings.updated', $actor, $settings, $before);

            return $settings->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function saveCategory(User $actor, ?int $categoryId, array $data, int $expectedRevision): StorefrontCategory
    {
        $companyId = $this->companyIdFor($actor);
        $title = trim((string) ($data['title'] ?? ''));
        $slug = Str::slug(trim((string) ($data['slug'] ?? $title)));
        $description = trim((string) ($data['description'] ?? '')) ?: null;
        if ($title === '' || mb_strlen($title) > 120 || $slug === '' || mb_strlen($slug) > 120) {
            throw ValidationException::withMessages(['title' => __('Add a valid category title and slug.')]);
        }
        if ($description !== null && mb_strlen($description) > 500) {
            throw ValidationException::withMessages(['description' => __('The category description may not exceed 500 characters.')]);
        }

        return DB::transaction(function () use ($actor, $companyId, $categoryId, $data, $title, $slug, $description, $expectedRevision): StorefrontCategory {
            $settings = $this->lockSettings($companyId, $expectedRevision);
            $category = $categoryId
                ? StorefrontCategory::withTrashed()->where('company_id', $companyId)->whereKey($categoryId)->lockForUpdate()->firstOrFail()
                : new StorefrontCategory(['company_id' => $companyId, 'created_by' => $actor->id]);
            $duplicate = StorefrontCategory::withTrashed()
                ->where('company_id', $companyId)
                ->where('slug', $slug)
                ->when($category->exists, fn ($query) => $query->whereKeyNot($category->id))
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['slug' => __('This storefront category slug is already used.')]);
            }
            $before = $category->exists ? $category->only(['slug', 'title', 'description', 'display_order', 'is_active']) : null;
            $category->fill([
                'slug' => $slug,
                'title' => $title,
                'description' => $description,
                'display_order' => (int) ($data['display_order'] ?? 0),
                'is_active' => (bool) ($data['is_active'] ?? false),
                'updated_by' => $actor->id,
            ])->save();
            $this->advanceRevision($settings, $actor);
            $this->audit('storefront.category.saved', $actor, $category, $before);

            return $category->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function saveProfile(User $actor, int $menuItemId, array $data, int $expectedRevision): StorefrontItemProfile
    {
        $companyId = $this->companyIdFor($actor);

        return DB::transaction(function () use ($actor, $companyId, $menuItemId, $data, $expectedRevision): StorefrontItemProfile {
            $settings = $this->lockSettings($companyId, $expectedRevision);
            $menuItem = MenuItem::query()->whereKey($menuItemId)->lockForUpdate()->firstOrFail();
            $profile = StorefrontItemProfile::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $settings->portal_branch_id)
                ->where('menu_item_id', $menuItem->id)
                ->lockForUpdate()
                ->first();
            $before = $profile?->only([
                'category_id', 'customer_title', 'short_description', 'direct_order_enabled', 'advance_days',
                'minimum_quantity', 'quantity_increment', 'maximum_quantity', 'is_chef_pick', 'display_order',
            ]);
            $profile ??= new StorefrontItemProfile([
                'company_id' => $companyId,
                'branch_id' => $settings->portal_branch_id,
                'menu_item_id' => $menuItem->id,
                'created_by' => $actor->id,
            ]);
            $profile->fill($this->validatedProfileData($menuItem, $settings, $data) + ['updated_by' => $actor->id])->save();
            $this->advanceRevision($settings, $actor);
            $this->audit('storefront.profile.saved', $actor, $profile, $before);

            return $profile->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function saveClosedDate(User $actor, ?int $closedDateId, array $data, int $expectedRevision): StorefrontClosedDate
    {
        $companyId = $this->companyIdFor($actor);

        return DB::transaction(function () use ($actor, $companyId, $closedDateId, $data, $expectedRevision): StorefrontClosedDate {
            $settings = $this->lockSettings($companyId, $expectedRevision);
            $date = trim((string) ($data['service_date'] ?? ''));
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw ValidationException::withMessages(['service_date' => __('Choose a valid closed date.')]);
            }
            $reason = trim((string) ($data['reason'] ?? '')) ?: null;
            if ($reason !== null && mb_strlen($reason) > 255) {
                throw ValidationException::withMessages(['reason' => __('The closed date reason may not exceed 255 characters.')]);
            }
            $record = $closedDateId
                ? StorefrontClosedDate::query()->where('company_id', $companyId)->whereKey($closedDateId)->lockForUpdate()->firstOrFail()
                : new StorefrontClosedDate([
                    'company_id' => $companyId,
                    'branch_id' => $settings->portal_branch_id,
                    'created_by' => $actor->id,
                ]);
            $before = $record->exists ? $record->only(['service_date', 'reason']) : null;
            $record->fill(['service_date' => $date, 'reason' => $reason, 'updated_by' => $actor->id])->save();
            $this->advanceRevision($settings, $actor);
            $this->audit('storefront.closed_date.saved', $actor, $record, $before);

            return $record->refresh();
        }, 3);
    }

    public function deleteClosedDate(User $actor, int $closedDateId, int $expectedRevision): void
    {
        $companyId = $this->companyIdFor($actor);
        DB::transaction(function () use ($actor, $companyId, $closedDateId, $expectedRevision): void {
            $settings = $this->lockSettings($companyId, $expectedRevision);
            $record = StorefrontClosedDate::query()->where('company_id', $companyId)->whereKey($closedDateId)->lockForUpdate()->firstOrFail();
            $before = $record->only(['service_date', 'reason']);
            $record->delete();
            $this->advanceRevision($settings, $actor);
            $this->auditLog->log('storefront.closed_date.deleted', $actor->id, StorefrontClosedDate::class, [
                'record_id' => $closedDateId,
                'before' => $before,
            ], $companyId);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function saveChannel(User $actor, string $code, array $data, int $expectedRevision): StorefrontDeliveryChannel
    {
        $companyId = $this->companyIdFor($actor);
        if (! isset(StorefrontDeliveryChannel::OPTIONS[$code])) {
            throw ValidationException::withMessages(['channel' => __('Choose a supported delivery application.')]);
        }
        $url = $this->validatedHttpsUrl($data['restaurant_url'] ?? null, 'restaurant_url');

        return DB::transaction(function () use ($actor, $companyId, $code, $data, $url, $expectedRevision): StorefrontDeliveryChannel {
            $settings = $this->lockSettings($companyId, $expectedRevision);
            $channel = StorefrontDeliveryChannel::query()->where('company_id', $companyId)->where('code', $code)->lockForUpdate()->first();
            $before = $channel?->only(['label', 'is_enabled', 'restaurant_url', 'display_order']);
            $channel ??= new StorefrontDeliveryChannel([
                'company_id' => $companyId,
                'code' => $code,
                'created_by' => $actor->id,
            ]);
            $channel->fill([
                'label' => StorefrontDeliveryChannel::OPTIONS[$code],
                'is_enabled' => (bool) ($data['is_enabled'] ?? false),
                'restaurant_url' => $url,
                'display_order' => (int) ($data['display_order'] ?? 0),
                'updated_by' => $actor->id,
            ])->save();
            $this->advanceRevision($settings, $actor);
            $this->audit('storefront.channel.saved', $actor, $channel, $before);

            return $channel->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function saveItemChannel(User $actor, int $profileId, int $channelId, array $data, int $expectedRevision): StorefrontItemChannel
    {
        $companyId = $this->companyIdFor($actor);
        $url = $this->validatedHttpsUrl($data['item_url'] ?? null, 'item_url');

        return DB::transaction(function () use ($actor, $companyId, $profileId, $channelId, $data, $url, $expectedRevision): StorefrontItemChannel {
            $settings = $this->lockSettings($companyId, $expectedRevision);
            $profile = StorefrontItemProfile::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $settings->portal_branch_id)
                ->whereKey($profileId)
                ->lockForUpdate()
                ->firstOrFail();
            $channel = StorefrontDeliveryChannel::query()
                ->where('company_id', $companyId)
                ->whereKey($channelId)
                ->lockForUpdate()
                ->firstOrFail();
            $link = StorefrontItemChannel::query()
                ->where('profile_id', $profile->id)
                ->where('channel_id', $channel->id)
                ->lockForUpdate()
                ->first();
            $before = $link?->only(['is_enabled', 'item_url']);
            $link ??= new StorefrontItemChannel([
                'profile_id' => $profile->id,
                'channel_id' => $channel->id,
                'created_by' => $actor->id,
            ]);
            $link->fill([
                'is_enabled' => (bool) ($data['is_enabled'] ?? false),
                'item_url' => $url,
                'updated_by' => $actor->id,
            ])->save();
            $this->advanceRevision($settings, $actor);
            $this->audit('storefront.item_channel.saved', $actor, $link, $before, $companyId);

            return $link->refresh();
        }, 3);
    }

    public function replaceProfileImage(User $actor, int $profileId, UploadedFile $image, int $expectedRevision): StorefrontItemProfile
    {
        $companyId = $this->companyIdFor($actor);
        $mime = (string) $image->getMimeType();
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
        if (! $extension || $image->getSize() <= 0 || $image->getSize() > 5 * 1024 * 1024
            || ! $image->getRealPath() || @getimagesize($image->getRealPath()) === false) {
            throw ValidationException::withMessages([
                'profile_image' => __('Choose a valid JPEG, PNG, or WebP image up to 5 MB.'),
            ]);
        }

        $disk = 'public';
        $path = 'storefront/'.$companyId.'/'.Str::uuid().'.'.$extension;
        if (! Storage::disk($disk)->putFileAs(dirname($path), $image, basename($path))) {
            throw ValidationException::withMessages(['profile_image' => __('The storefront image could not be stored.')]);
        }

        try {
            return DB::transaction(function () use ($actor, $companyId, $profileId, $expectedRevision, $disk, $path): StorefrontItemProfile {
                $settings = $this->lockSettings($companyId, $expectedRevision);
                $profile = StorefrontItemProfile::query()
                    ->where('company_id', $companyId)
                    ->where('branch_id', $settings->portal_branch_id)
                    ->whereKey($profileId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $before = $profile->only(['image_disk', 'image_path']);
                $oldDisk = $profile->image_disk;
                $oldPath = $profile->image_path;
                $profile->update([
                    'image_disk' => $disk,
                    'image_path' => $path,
                    'updated_by' => $actor->id,
                ]);
                $this->advanceRevision($settings, $actor);
                $this->audit('storefront.profile.image_replaced', $actor, $profile, $before);
                if ($oldDisk && $oldPath && ($oldDisk !== $disk || $oldPath !== $path)) {
                    DB::afterCommit(fn () => Storage::disk((string) $oldDisk)->delete((string) $oldPath));
                }

                return $profile->refresh();
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    public function removeProfileImage(User $actor, int $profileId, int $expectedRevision): StorefrontItemProfile
    {
        $companyId = $this->companyIdFor($actor);

        return DB::transaction(function () use ($actor, $companyId, $profileId, $expectedRevision): StorefrontItemProfile {
            $settings = $this->lockSettings($companyId, $expectedRevision);
            $profile = StorefrontItemProfile::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $settings->portal_branch_id)
                ->whereKey($profileId)
                ->lockForUpdate()
                ->firstOrFail();
            $before = $profile->only(['image_disk', 'image_path']);
            $oldDisk = $profile->image_disk;
            $oldPath = $profile->image_path;
            $profile->update(['image_disk' => null, 'image_path' => null, 'updated_by' => $actor->id]);
            $this->advanceRevision($settings, $actor);
            $this->audit('storefront.profile.image_removed', $actor, $profile, $before);
            if ($oldDisk && $oldPath) {
                DB::afterCommit(fn () => Storage::disk((string) $oldDisk)->delete((string) $oldPath));
            }

            return $profile->refresh();
        }, 3);
    }

    public function disableAndHideMenuItem(User $actor, int $menuItemId, int $expectedRevision): void
    {
        $companyId = $this->companyIdFor($actor);
        DB::transaction(function () use ($actor, $companyId, $menuItemId, $expectedRevision): void {
            $settings = $this->lockSettings($companyId, $expectedRevision);
            $item = MenuItem::query()->whereKey($menuItemId)->lockForUpdate()->firstOrFail();
            $before = ['item_active' => (bool) $item->is_active];
            $profile = StorefrontItemProfile::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $settings->portal_branch_id)
                ->where('menu_item_id', $item->id)
                ->lockForUpdate()
                ->first();
            if ($profile) {
                $before['profile'] = $profile->only(['direct_order_enabled', 'is_chef_pick']);
                $profile->update([
                    'direct_order_enabled' => false,
                    'is_chef_pick' => false,
                    'updated_by' => $actor->id,
                ]);
                StorefrontItemChannel::query()
                    ->where('profile_id', $profile->id)
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (StorefrontItemChannel $link) => $link->update([
                        'is_enabled' => false,
                        'updated_by' => $actor->id,
                    ]));
            }
            $item->update(['is_active' => false]);
            $this->advanceRevision($settings, $actor);
            $this->audit('storefront.catalog_item.disabled_and_hidden', $actor, $item, $before, $companyId);
        }, 3);
    }

    public function permanentlyDeleteUnusedMenuItem(User $actor, int $menuItemId, int $expectedRevision): void
    {
        $companyId = $this->companyIdFor($actor);
        DB::transaction(function () use ($actor, $companyId, $menuItemId, $expectedRevision): void {
            $settings = $this->lockSettings($companyId, $expectedRevision);
            $item = MenuItem::query()->whereKey($menuItemId)->lockForUpdate()->firstOrFail();
            $this->menuItemUsage->lockReferencesForUpdate($item->id);
            $usage = $this->menuItemUsage->usageReport($item->id);
            if ((int) $usage['total_references'] > 0) {
                throw ValidationException::withMessages([
                    'cleanup' => __('This menu item has references and cannot be permanently deleted. Disable and hide it instead.'),
                ]);
            }
            $snapshot = $item->only(['id', 'code', 'name', 'arabic_name', 'category_id', 'is_active']);
            $item->delete();
            $this->advanceRevision($settings, $actor);
            $this->auditLog->log('storefront.catalog_item.deleted', $actor->id, MenuItem::class, [
                'before' => $snapshot,
                'usage' => $usage,
            ], $companyId);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function validatedProfileData(MenuItem $menuItem, StorefrontSetting $settings, array $data): array
    {
        $title = trim((string) ($data['customer_title'] ?? '')) ?: null;
        $description = trim((string) ($data['short_description'] ?? '')) ?: null;
        $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $advanceDays = filter_var($data['advance_days'] ?? null, FILTER_VALIDATE_INT);
        $minimumText = trim((string) ($data['minimum_quantity'] ?? ''));
        $incrementText = trim((string) ($data['quantity_increment'] ?? ''));
        $maximumText = trim((string) ($data['maximum_quantity'] ?? ''));
        if (! $this->validQuantityText($minimumText)
            || ! $this->validQuantityText($incrementText)
            || $maximumText !== '' && ! $this->validQuantityText($maximumText)) {
            throw ValidationException::withMessages(['minimum_quantity' => __('Quantities must be positive numbers with at most three decimal places.')]);
        }
        $minimum = MinorUnits::parseQtyMilli($minimumText);
        $increment = MinorUnits::parseQtyMilli($incrementText);
        $maximum = $maximumText === '' ? null : MinorUnits::parseQtyMilli($maximumText);
        if ($title !== null && mb_strlen($title) > 120) {
            throw ValidationException::withMessages(['customer_title' => __('The customer title may not exceed 120 characters.')]);
        }
        if ($description !== null && mb_strlen($description) > 280) {
            throw ValidationException::withMessages(['short_description' => __('The customer description may not exceed 280 characters.')]);
        }
        if ($advanceDays === false || $advanceDays < 1 || $advanceDays > 365) {
            throw ValidationException::withMessages(['advance_days' => __('Advance days must be between 1 and 365.')]);
        }
        if ($minimum <= 0 || $increment <= 0 || $maximum !== null && $maximum < $minimum) {
            throw ValidationException::withMessages(['minimum_quantity' => __('Add a valid minimum, increment, and optional maximum quantity.')]);
        }
        if ((bool) ($data['direct_order_enabled'] ?? false)) {
            $category = $categoryId ? StorefrontCategory::query()->where('company_id', $settings->company_id)->whereKey($categoryId)->where('is_active', true)->first() : null;
            $branchMapped = DB::table('menu_item_branches')
                ->where('menu_item_id', $menuItem->id)
                ->where('branch_id', $settings->portal_branch_id)
                ->exists();
            if (! $category || ! $menuItem->is_active || ! $branchMapped
                || ! isset(MenuItem::unitOptions()[$menuItem->unit])
                || MinorUnits::parse((string) $menuItem->selling_price_per_unit, 100) <= 0) {
                throw ValidationException::withMessages([
                    'direct_order_enabled' => __('Resolve the category, item status, branch, unit, and positive price blockers before publishing.'),
                ]);
            }
        }

        return [
            'category_id' => $categoryId,
            'customer_title' => $title,
            'short_description' => $description,
            'direct_order_enabled' => (bool) ($data['direct_order_enabled'] ?? false),
            'advance_days' => $advanceDays,
            'minimum_quantity' => MinorUnits::format($minimum, 1000),
            'quantity_increment' => MinorUnits::format($increment, 1000),
            'maximum_quantity' => $maximum !== null ? MinorUnits::format($maximum, 1000) : null,
            'is_chef_pick' => (bool) ($data['is_chef_pick'] ?? false),
            'display_order' => (int) ($data['display_order'] ?? 0),
        ];
    }

    private function validatedHttpsUrl(mixed $value, string $attribute): ?string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return null;
        }
        if (mb_strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw ValidationException::withMessages([$attribute => __('Use a valid HTTPS destination URL.')]);
        }

        return $url;
    }

    private function validQuantityText(string $value): bool
    {
        return preg_match('/^\d+(?:\.\d{1,3})?$/', $value) === 1;
    }

    private function lockSettings(int $companyId, int $expectedRevision): StorefrontSetting
    {
        $settings = StorefrontSetting::query()->where('company_id', $companyId)->lockForUpdate()->first();
        $this->assertRevision($settings, $expectedRevision);
        if (! $settings) {
            throw ValidationException::withMessages(['settings' => __('Save storefront settings before managing the catalog.')]);
        }

        return $settings;
    }

    private function assertRevision(?StorefrontSetting $settings, int $expectedRevision): void
    {
        $current = (int) ($settings?->revision ?? 0);
        if ($expectedRevision !== $current) {
            throw ValidationException::withMessages([
                'revision' => __('The storefront changed in another session. Refresh before saving again.'),
            ]);
        }
    }

    private function advanceRevision(StorefrontSetting $settings, User $actor): void
    {
        $settings->update([
            'revision' => (int) $settings->revision + 1,
            'updated_by' => $actor->id,
        ]);
    }

    private function audit(string $action, User $actor, Model $subject, ?array $before, ?int $companyId = null): void
    {
        $this->auditLog->log($action, $actor->id, $subject, [
            'before' => $before,
            'after' => $subject->getAttributes(),
        ], $companyId);
    }
}

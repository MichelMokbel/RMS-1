<?php

use App\Models\AccountingAuditLog;
use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StorefrontCategory;
use App\Models\StorefrontDeliveryChannel;
use App\Models\StorefrontItemChannel;
use App\Models\StorefrontItemProfile;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\Storefront\StorefrontAdministrationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function storefrontAdministrator(): User
{
    $actor = User::factory()->create(['status' => 'active']);
    $actor->assignRole(Role::findOrCreate('admin', 'web'));

    return $actor;
}

it('allows only the storefront administrator to open the workspace', function (): void {
    $manager = User::factory()->create(['status' => 'active']);
    $manager->assignRole(Role::findOrCreate('manager', 'web'));
    $this->actingAs($manager)->get('/storefront')->assertForbidden();

    $admin = storefrontAdministrator();
    $this->actingAs($admin)->get('/storefront')
        ->assertOk()
        ->assertSee('Customer Storefront');
});

it('does not load a storefront category owned by another company', function (): void {
    $admin = storefrontAdministrator();
    $otherCompany = AccountingCompany::query()->create([
        'name' => 'Other Storefront Company',
        'code' => 'OTHER-STOREFRONT',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => false,
    ]);
    $foreignCategory = StorefrontCategory::query()->create([
        'company_id' => $otherCompany->id,
        'slug' => 'private-category',
        'title' => 'Private Category',
        'is_active' => true,
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ]);

    expect(fn () => Volt::actingAs($admin)
        ->test('storefront.index')
        ->call('editCategory', $foreignCategory->id))
        ->toThrow(ModelNotFoundException::class);
});

it('enables one company-owned checkout add-on category and audits the setting', function (): void {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $branch = Branch::query()->findOrFail(1);
    $branch->update(['company_id' => $company->id, 'is_active' => true]);
    $admin = storefrontAdministrator();
    $service = app(StorefrontAdministrationService::class);
    $settings = $service->saveSettings($admin, [
        'portal_branch_id' => $branch->id,
        'normal_menu_enabled' => false,
        'checkout_upsell_enabled' => false,
        'menu_cutoff_time' => '23:00',
        'delivery_apps_enabled' => false,
        'daily_dish_plan_variant' => 'balanced',
    ], 0);
    $category = StorefrontCategory::query()->create([
        'company_id' => $company->id,
        'slug' => 'checkout-add-ons',
        'title' => 'Checkout Add-ons',
        'is_active' => true,
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ]);
    $otherCompany = AccountingCompany::query()->create([
        'name' => 'Other Upsell Company',
        'code' => 'OTHER-UPSELL',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => false,
    ]);
    $foreignCategory = StorefrontCategory::query()->create([
        'company_id' => $otherCompany->id,
        'slug' => 'foreign-add-ons',
        'title' => 'Foreign Add-ons',
        'is_active' => true,
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ]);

    expect(fn () => $service->saveSettings($admin, [
        'portal_branch_id' => $branch->id,
        'checkout_upsell_enabled' => true,
        'menu_cutoff_time' => '23:00',
    ], $settings->revision))->toThrow(ValidationException::class);
    expect(fn () => $service->saveSettings($admin, [
        'portal_branch_id' => $branch->id,
        'checkout_upsell_enabled' => true,
        'upsell_category_id' => $foreignCategory->id,
        'menu_cutoff_time' => '23:00',
    ], $settings->revision))->toThrow(ValidationException::class);

    $settings = $service->saveSettings($admin, [
        'portal_branch_id' => $branch->id,
        'normal_menu_enabled' => true,
        'checkout_upsell_enabled' => true,
        'upsell_category_id' => $category->id,
        'menu_cutoff_time' => '23:00',
        'delivery_apps_enabled' => false,
        'daily_dish_plan_variant' => '3',
    ], $settings->revision);

    expect($settings->checkout_upsell_enabled)->toBeTrue()
        ->and((int) $settings->upsell_category_id)->toBe((int) $category->id)
        ->and($settings->normal_menu_enabled)->toBeTrue()
        ->and($settings->daily_dish_plan_variant)->toBe('3')
        ->and(AccountingAuditLog::query()->where('action', 'storefront.settings.updated')->count())->toBe(2);
});

it('rejects an unsupported Daily Dish plan layout', function (): void {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $branch = Branch::query()->findOrFail(1);
    $branch->update(['company_id' => $company->id, 'is_active' => true]);
    $admin = storefrontAdministrator();

    expect(fn () => app(StorefrontAdministrationService::class)->saveSettings($admin, [
        'portal_branch_id' => $branch->id,
        'menu_cutoff_time' => '23:00',
        'daily_dish_plan_variant' => 'invented',
    ], 0))->toThrow(ValidationException::class);
});

it('saves versioned settings categories and a publishable item with an audit trail', function (): void {
    Storage::fake('public');
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $branch = Branch::query()->findOrFail(1);
    $branch->update(['company_id' => $company->id, 'is_active' => true]);
    $admin = storefrontAdministrator();
    $service = app(StorefrontAdministrationService::class);

    $settings = $service->saveSettings($admin, [
        'portal_branch_id' => $branch->id,
        'normal_menu_enabled' => false,
        'menu_cutoff_time' => '23:00',
        'delivery_apps_enabled' => true,
    ], 0);
    expect($settings->revision)->toBe(1)
        ->and($settings->normal_menu_enabled)->toBeFalse()
        ->and($settings->delivery_apps_enabled)->toBeTrue();

    $category = $service->saveCategory($admin, null, [
        'title' => 'Family Meals',
        'slug' => 'family-meals',
        'description' => 'Meals prepared in advance.',
        'display_order' => 1,
        'is_active' => true,
    ], 1);
    $item = MenuItem::factory()->create([
        'name' => 'Roast Chicken Tray',
        'selling_price_per_unit' => '120.000',
        'unit' => MenuItem::UNIT_EACH,
        'is_active' => true,
    ]);
    DB::table('menu_item_branches')->updateOrInsert([
        'menu_item_id' => $item->id,
        'branch_id' => $branch->id,
    ], ['created_at' => now(), 'updated_at' => now()]);
    $profile = $service->saveProfile($admin, $item->id, [
        'category_id' => $category->id,
        'customer_title' => 'Roast Chicken for Six',
        'short_description' => 'Delivery included.',
        'direct_order_enabled' => true,
        'advance_days' => 3,
        'minimum_quantity' => '1',
        'quantity_increment' => '1',
        'maximum_quantity' => '4',
        'is_chef_pick' => true,
        'display_order' => 2,
    ], 2);

    expect($profile)->toBeInstanceOf(StorefrontItemProfile::class)
        ->and($profile->direct_order_enabled)->toBeTrue()
        ->and($profile->advance_days)->toBe(3)
        ->and(StorefrontSetting::query()->where('company_id', $company->id)->value('revision'))->toBe(3)
        ->and(AccountingAuditLog::query()->where('action', 'storefront.settings.updated')->count())->toBe(1)
        ->and(AccountingAuditLog::query()->where('action', 'storefront.category.saved')->count())->toBe(1)
        ->and(AccountingAuditLog::query()->where('action', 'storefront.profile.saved')->count())->toBe(1);

    $profile = $service->replaceProfileImage(
        $admin,
        $profile->id,
        UploadedFile::fake()->image('dish.png', 120, 90)->size(100),
        3,
    );
    Storage::disk('public')->assertExists((string) $profile->image_path);
    expect($profile->image_disk)->toBe('public')
        ->and(StorefrontSetting::query()->where('company_id', $company->id)->value('revision'))->toBe(4)
        ->and(AccountingAuditLog::query()->where('action', 'storefront.profile.image_replaced')->count())->toBe(1);

    $storedPath = (string) $profile->image_path;
    $profile = $service->removeProfileImage($admin, $profile->id, 4);
    Storage::disk('public')->assertMissing($storedPath);
    expect($profile->image_path)->toBeNull()
        ->and(StorefrontSetting::query()->where('company_id', $company->id)->value('revision'))->toBe(5);

    expect(fn () => $service->saveCategory($admin, $category->id, [
        'title' => 'Stale change',
        'slug' => 'stale-change',
        'is_active' => true,
    ], 2))->toThrow(ValidationException::class);
});

it('blocks direct publication until canonical item and category requirements are satisfied', function (): void {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $branch = Branch::query()->findOrFail(1);
    $branch->update(['company_id' => $company->id, 'is_active' => true]);
    $admin = storefrontAdministrator();
    $service = app(StorefrontAdministrationService::class);
    $service->saveSettings($admin, [
        'portal_branch_id' => $branch->id,
        'normal_menu_enabled' => false,
        'menu_cutoff_time' => '23:00',
        'delivery_apps_enabled' => false,
    ], 0);
    $inactiveCategory = StorefrontCategory::query()->create([
        'company_id' => $company->id,
        'slug' => 'hidden',
        'title' => 'Hidden',
        'is_active' => false,
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ]);
    $item = MenuItem::factory()->create([
        'selling_price_per_unit' => '0.000',
        'unit' => MenuItem::UNIT_EACH,
        'is_active' => false,
    ]);

    expect(fn () => $service->saveProfile($admin, $item->id, [
        'category_id' => $inactiveCategory->id,
        'direct_order_enabled' => true,
        'advance_days' => 1,
        'minimum_quantity' => '1',
        'quantity_increment' => '1',
    ], 1))->toThrow(ValidationException::class)
        ->and(StorefrontItemProfile::query()->count())->toBe(0);

    expect(fn () => $service->saveProfile($admin, $item->id, [
        'direct_order_enabled' => false,
        'advance_days' => 1,
        'minimum_quantity' => '1.0000',
        'quantity_increment' => '1',
    ], 1))->toThrow(ValidationException::class);
});

it('reports references and only permanently deletes an explicitly reviewed unused item', function (): void {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $branch = Branch::query()->findOrFail(1);
    $branch->update(['company_id' => $company->id, 'is_active' => true]);
    $admin = storefrontAdministrator();
    $service = app(StorefrontAdministrationService::class);
    $service->saveSettings($admin, [
        'portal_branch_id' => $branch->id,
        'normal_menu_enabled' => false,
        'menu_cutoff_time' => '23:00',
        'delivery_apps_enabled' => false,
    ], 0);

    $referenced = MenuItem::factory()->create(['is_active' => true]);
    OrderItem::factory()->create([
        'order_id' => Order::factory()->create(['branch_id' => $branch->id])->id,
        'menu_item_id' => $referenced->id,
    ]);
    expect(fn () => $service->permanentlyDeleteUnusedMenuItem($admin, $referenced->id, 1))
        ->toThrow(ValidationException::class);
    $service->disableAndHideMenuItem($admin, $referenced->id, 1);
    expect($referenced->fresh()->is_active)->toBeFalse();

    $unused = MenuItem::factory()->create(['is_active' => false, 'recipe_id' => null]);
    $service->permanentlyDeleteUnusedMenuItem($admin, $unused->id, 2);
    expect(MenuItem::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(AccountingAuditLog::query()->where('action', 'storefront.catalog_item.deleted')->count())->toBe(1);

    $this->actingAs($admin)->get('/storefront/catalog-cleanup.csv')
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

it('keeps delivery application links public when direct menu ordering is disabled without creating business records', function (): void {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $branch = Branch::query()->findOrFail(1);
    $branch->update(['company_id' => $company->id, 'is_active' => true]);
    $admin = storefrontAdministrator();
    $service = app(StorefrontAdministrationService::class);
    $service->saveSettings($admin, [
        'portal_branch_id' => $branch->id,
        'normal_menu_enabled' => false,
        'menu_cutoff_time' => '23:00',
        'delivery_apps_enabled' => true,
    ], 0);
    $item = MenuItem::factory()->create([
        'name' => 'Application Only Tray',
        'selling_price_per_unit' => '75.000',
        'unit' => MenuItem::UNIT_EACH,
        'is_active' => true,
    ]);
    DB::table('menu_item_branches')->updateOrInsert([
        'menu_item_id' => $item->id,
        'branch_id' => $branch->id,
    ], [
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $profile = StorefrontItemProfile::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'menu_item_id' => $item->id,
        'customer_title' => 'Family Tray',
        'direct_order_enabled' => false,
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ]);
    $channel = StorefrontDeliveryChannel::query()->create([
        'company_id' => $company->id,
        'code' => 'talabat',
        'label' => 'Talabat',
        'is_enabled' => true,
        'restaurant_url' => 'https://example.test/restaurant',
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ]);
    StorefrontItemChannel::query()->create([
        'profile_id' => $profile->id,
        'channel_id' => $channel->id,
        'is_enabled' => true,
        'item_url' => 'https://example.test/family-tray',
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ]);

    $this->getJson('/api/public/storefront')->assertNotFound();
    $response = $this->getJson('/api/public/storefront/delivery-apps')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'talabat')
        ->assertJsonPath('data.0.items.0.title', 'Family Tray')
        ->assertJsonPath('data.0.items.0.destination_url', 'https://example.test/family-tray');
    expect($response->json('data.0.items.0'))->not->toHaveKeys(['unit_price_cents', 'earliest_service_date', 'minimum_quantity'])
        ->and(DB::table('orders')->count())->toBe(0)
        ->and(DB::table('payments')->count())->toBe(0);
});

<?php

use App\Models\HelpArticle;
use App\Models\HelpArticleAsset;
use App\Models\User;
use App\Services\Ai\AiProviderInterface;
use App\Services\Help\HelpCaptureService;
use Database\Seeders\HelpContentSeeder;
use Database\Seeders\HelpDemoSeeder;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function helpUser(string $roleName): User
{
    $role = Role::findOrCreate($roleName, 'web');
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function helpPermissionUser(string $permissionName): User
{
    $permission = Permission::findOrCreate($permissionName, 'web');
    $user = User::factory()->create(['status' => 'active']);
    $user->givePermissionTo($permission);

    return $user;
}

it('shows only visible published help articles to the current user', function () {
    $cashier = helpUser('cashier');

    HelpArticle::query()->create([
        'title' => 'Cashier Guide',
        'slug' => 'cashier-guide',
        'module' => 'orders',
        'summary' => 'Visible guide',
        'locale' => 'en',
        'status' => 'published',
        'visibility_mode' => 'scoped',
        'allowed_roles' => ['cashier'],
        'allowed_permissions' => [],
    ]);

    HelpArticle::query()->create([
        'title' => 'Manager Guide',
        'slug' => 'manager-guide',
        'module' => 'reports',
        'summary' => 'Hidden guide',
        'locale' => 'en',
        'status' => 'published',
        'visibility_mode' => 'scoped',
        'allowed_roles' => ['manager'],
        'allowed_permissions' => [],
    ]);

    $this->actingAs($cashier)
        ->get(route('help.index'))
        ->assertOk()
        ->assertSee('Cashier Guide')
        ->assertDontSee('Manager Guide');
});

it('allows managers to manage help articles via the Volt page', function () {
    $manager = helpUser('manager');

    Volt::actingAs($manager);

    Volt::test('help.manage')
        ->call('newArticle')
        ->set('title', 'Receiving Purchase Orders')
        ->set('slug', 'receiving-purchase-orders')
        ->set('module', 'purchasing')
        ->set('summary', 'How to receive purchase orders')
        ->set('body_markdown', 'Use this when stock arrives.')
        ->set('status', 'published')
        ->set('steps', [
            ['title' => 'Open the PO', 'body_markdown' => 'Find the purchase order.', 'image_key' => '', 'cta_label' => '', 'cta_route' => ''],
        ])
        ->set('faqs', [
            ['question' => 'Who should receive?', 'answer_markdown' => 'A manager or authorized operations user.'],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(HelpArticle::query()->where('slug', 'receiving-purchase-orders')->exists())->toBeTrue();
});

it('forbids cashiers from opening help management', function () {
    $cashier = helpUser('cashier');

    $this->actingAs($cashier)
        ->get(route('help.manage'))
        ->assertForbidden();
});

it('returns cited bot answers from approved help content', function () {
    $cashier = helpUser('cashier');

    $article = HelpArticle::query()->create([
        'title' => 'Create Orders',
        'slug' => 'create-orders',
        'module' => 'orders',
        'summary' => 'Guide for creating orders',
        'locale' => 'en',
        'status' => 'published',
        'visibility_mode' => 'scoped',
        'allowed_roles' => ['cashier'],
        'allowed_permissions' => [],
    ]);

    $article->steps()->create([
        'sort_order' => 1,
        'title' => 'Open Orders',
        'body_markdown' => 'Go to Orders and click create.',
    ]);

    app()->bind(AiProviderInterface::class, fn () => new class implements AiProviderInterface {
        public function generateStructured(array $messages, array $schema): array
        {
            return [
                'answer_markdown' => 'Open the Orders screen and click Create Order.',
                'confidence' => 'high',
                'fallback' => false,
                'citations' => [[
                    'article_slug' => 'create-orders',
                    'article_title' => 'Create Orders',
                    'step_id' => 1,
                    'route_name' => 'orders.index',
                ]],
            ];
        }
    });

    $response = $this->actingAs($cashier)->postJson(route('help.bot.respond'), [
        'message' => 'How do I create an order?',
    ]);

    $response->assertOk()
        ->assertJsonPath('citations.0.article_slug', 'create-orders')
        ->assertJsonPath('fallback', false);
});

it('falls back to the nearest available screenshot when a help step has no image key', function () {
    $manager = helpUser('manager');

    $article = HelpArticle::query()->create([
        'title' => 'Fallback Screenshot Guide',
        'slug' => 'fallback-screenshot-guide',
        'module' => 'orders',
        'summary' => 'Guide with partial screenshot coverage',
        'locale' => 'en',
        'status' => 'published',
        'visibility_mode' => 'all',
        'allowed_roles' => [],
        'allowed_permissions' => [],
    ]);

    HelpArticleAsset::query()->create([
        'article_id' => $article->id,
        'key' => 'fallback-screenshot-guide.primary',
        'disk' => 'public',
        'path' => 'help/testing/fallback.png',
        'alt_text' => 'Fallback screenshot',
        'viewport' => 'desktop',
    ]);

    $article->steps()->create([
        'sort_order' => 1,
        'title' => 'Step with screenshot',
        'body_markdown' => 'Visible screenshot.',
        'image_key' => 'fallback-screenshot-guide.primary',
    ]);

    $article->steps()->create([
        'sort_order' => 2,
        'title' => 'Step without screenshot',
        'body_markdown' => 'Should inherit the nearest screenshot.',
        'image_key' => null,
    ]);

    $this->actingAs($manager)
        ->get(route('help.show', $article))
        ->assertOk()
        ->assertSee('/storage/help/testing/fallback.png')
        ->assertDontSee('Screenshot will appear here after running the automated capture command.');
});

it('seeds the expanded operational guides with steps and faqs', function () {
    app(HelpContentSeeder::class)->run();

    $expected = [
        ['slug' => 'recipe-crud-and-production', 'module' => 'catalog'],
        ['slug' => 'company-food-project-administration', 'module' => 'company_food'],
        ['slug' => 'meal-subscriptions-lifecycle', 'module' => 'subscriptions'],
        ['slug' => 'meal-plan-requests-review-and-conversion', 'module' => 'subscriptions'],
    ];

    foreach ($expected as $guide) {
        $article = HelpArticle::query()->where('slug', $guide['slug'])->first();

        expect($article)->not->toBeNull();
        expect($article?->module)->toBe($guide['module']);
        expect($article?->steps()->count())->toBeGreaterThan(0);
        expect($article?->faqs()->count())->toBeGreaterThan(0);
    }
});

it('scopes recipe and operations help guides by permission', function () {
    app(HelpContentSeeder::class)->run();

    $catalogUser = helpPermissionUser('catalog.access');
    $opsUser = helpPermissionUser('operations.access');

    $this->actingAs($catalogUser)
        ->get(route('help.index'))
        ->assertOk()
        ->assertSee('Recipe CRUD and Production')
        ->assertDontSee('Company Food Project Administration')
        ->assertDontSee('Meal Subscriptions Lifecycle')
        ->assertDontSee('Meal Plan Requests Review and Conversion');

    $this->actingAs($opsUser)
        ->get(route('help.index'))
        ->assertOk()
        ->assertSee('Company Food Project Administration')
        ->assertSee('Meal Subscriptions Lifecycle')
        ->assertSee('Meal Plan Requests Review and Conversion')
        ->assertDontSee('Recipe CRUD and Production');
});

it('builds a valid capture manifest after seeding help demo data', function () {
    app(HelpContentSeeder::class)->run();
    app(HelpDemoSeeder::class)->run();

    $errors = app(HelpCaptureService::class)->validateManifest();

    expect($errors)->toBe([]);
    expect(app(HelpCaptureService::class)->buildManifest())->not->toBeEmpty();
});

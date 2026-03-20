<?php

namespace Database\Seeders;

use App\Models\HelpArticle;
use App\Models\HelpArticleAsset;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class HelpContentSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('help_articles')) {
            return;
        }

        foreach ($this->articles() as $articleData) {
            $articleData['steps'] = $this->normalizeSteps($articleData['steps']);

            $article = HelpArticle::query()->updateOrCreate(
                ['slug' => $articleData['slug']],
                collect($articleData)->except(['steps', 'faqs'])->all(),
            );

            $article->steps()->delete();
            $article->faqs()->delete();

            foreach ($articleData['steps'] as $index => $step) {
                $article->steps()->create([
                    'sort_order' => $index + 1,
                    'title' => $step['title'],
                    'body_markdown' => $step['body_markdown'],
                    'image_key' => $step['image_key'] ?? null,
                    'cta_label' => $step['cta_label'] ?? null,
                    'cta_route' => $step['cta_route'] ?? null,
                    'cta_route_params' => $step['cta_route_params'] ?? null,
                ]);

                if (! empty($step['image_key'])) {
                    HelpArticleAsset::query()->updateOrCreate(
                        ['key' => $step['image_key']],
                        [
                            'article_id' => $article->id,
                            'alt_text' => $step['title'].' screenshot',
                            'viewport' => 'desktop',
                            'meta' => [
                                'scenario_key' => $step['image_key'],
                            ],
                        ],
                    );
                }
            }

            foreach ($articleData['faqs'] as $index => $faq) {
                $article->faqs()->create([
                    'sort_order' => $index + 1,
                    'module' => $article->module,
                    'question' => $faq['question'],
                    'answer_markdown' => $faq['answer_markdown'],
                ]);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSteps(array $steps): array
    {
        return collect($steps)
            ->values()
            ->map(function (array $step, int $index) use ($steps): array {
                if (! empty($step['image_key'])) {
                    return $step;
                }

                $step['image_key'] = $this->nearestImageKey($steps, $index);

                return $step;
            })
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function nearestImageKey(array $steps, int $index): ?string
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $imageKey = $steps[$cursor]['image_key'] ?? null;

            if (is_string($imageKey) && $imageKey !== '') {
                return $imageKey;
            }
        }

        for ($cursor = $index + 1, $count = count($steps); $cursor < $count; $cursor++) {
            $imageKey = $steps[$cursor]['image_key'] ?? null;

            if (is_string($imageKey) && $imageKey !== '') {
                return $imageKey;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function articles(): array
    {
        return [
            [
                'title' => 'Logging In and Navigating the System',
                'slug' => 'login-and-navigation',
                'module' => 'general',
                'summary' => 'Start with the correct user account, confirm your role-based menu, and use the sidebar to move between RMS-1 modules.',
                'body_markdown' => "Use this guide when a user needs help signing in or finding where a task lives.\n\nThe menu is role-based, so different users may not see the same sections.",
                'prerequisites' => ['An active RMS-1 user account', 'Your username and password'],
                'keywords' => ['login', 'sign in', 'navigation', 'sidebar', 'dashboard'],
                'target_route' => 'home',
                'target_route_params' => [],
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'all',
                'allowed_roles' => [],
                'allowed_permissions' => [],
                'sort_order' => 10,
                'steps' => [
                    [
                        'title' => 'Open RMS-1 and sign in with your assigned username.',
                        'body_markdown' => "Enter your **username** and **password** on the login screen.\n\nIf your role is active, RMS-1 will redirect you to the most relevant starting page.",
                        'image_key' => 'login-and-navigation.dashboard',
                        'cta_label' => 'Open Home',
                        'cta_route' => 'home',
                    ],
                    [
                        'title' => 'Use the sidebar groups to reach your work area.',
                        'body_markdown' => "The sidebar groups the system by business area: **Orders**, **Catalog**, **Operations**, **Receivables**, **Finance**, and **Reports**.\n\nOnly the sections allowed by your role and permissions are shown.",
                    ],
                    [
                        'title' => 'Use Help whenever you are unsure what screen to open.',
                        'body_markdown' => "Open the Help Center from the sidebar. Search by task name such as `create customer`, `daily dish`, or `inventory transfer`.",
                        'cta_label' => 'Open Help',
                        'cta_route' => 'help.index',
                    ],
                    [
                        'title' => 'If a page is missing, check your role instead of guessing.',
                        'body_markdown' => "If you cannot see a menu item mentioned in a guide, your account may not have the required role or permission.\n\nContact an administrator instead of using another user’s account.",
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'Why do I see a different sidebar from another employee?',
                        'answer_markdown' => 'RMS-1 hides modules that are not assigned to your role or direct permissions.',
                    ],
                    [
                        'question' => 'Where should I start if I only need reports?',
                        'answer_markdown' => 'Open **Reports** from the sidebar. If you are redirected after login, RMS-1 usually sends staff users there automatically.',
                    ],
                    [
                        'question' => 'What should I do if login keeps failing?',
                        'answer_markdown' => 'Confirm the username first, then reset the password or ask an administrator to verify that your account is still active.',
                    ],
                ],
            ],
            [
                'title' => 'Creating and Editing Orders',
                'slug' => 'create-and-edit-orders',
                'module' => 'orders',
                'summary' => 'Use the Orders hub to review existing orders, open the create form, add items, and save changes before production starts.',
                'body_markdown' => 'This guide covers the common backoffice order flow for cashiers and managers.',
                'prerequisites' => ['Orders access', 'At least one active branch', 'Menu items available in the target branch'],
                'keywords' => ['orders', 'create order', 'edit order', 'cashier'],
                'target_route' => 'orders.index',
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'scoped',
                'allowed_roles' => ['admin', 'manager', 'cashier', 'waiter'],
                'allowed_permissions' => ['orders.access'],
                'sort_order' => 20,
                'steps' => [
                    [
                        'title' => 'Open the Orders list to review the current queue.',
                        'body_markdown' => 'Use filters and search to find an existing order before creating a new one.',
                        'image_key' => 'create-and-edit-orders.list',
                        'cta_label' => 'Open Orders',
                        'cta_route' => 'orders.index',
                    ],
                    [
                        'title' => 'Click **Create Order** to open the entry form.',
                        'body_markdown' => 'Choose the branch, order source, order type, and customer details before adding line items.',
                        'cta_label' => 'Create Order',
                        'cta_route' => 'orders.create',
                    ],
                    [
                        'title' => 'Add menu items and confirm quantities.',
                        'body_markdown' => "Search for menu items by code or name.\n\nReview quantity, price, and any order-level notes before saving.",
                        'image_key' => 'create-and-edit-orders.form',
                    ],
                    [
                        'title' => 'Save the order, then reopen it from the list if you need to edit it.',
                        'body_markdown' => 'Only update an order before kitchen or production steps make it read-only for the current workflow.',
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'Why is a menu item missing from the order form?',
                        'answer_markdown' => 'The item may be inactive or not available in the selected branch.',
                    ],
                    [
                        'question' => 'Can I edit an order after the kitchen has started working on it?',
                        'answer_markdown' => 'That depends on the current workflow state. If the order is already in production, avoid manual edits and follow your operational policy.',
                    ],
                    [
                        'question' => 'Where can I print kitchen tickets or invoice-style order prints?',
                        'answer_markdown' => 'Use the order print routes and kitchen print views from the Orders area and Reports area as needed.',
                    ],
                ],
            ],
            [
                'title' => 'POS Install and Shift Flow',
                'slug' => 'pos-install-and-shift-flow',
                'module' => 'pos',
                'summary' => 'Install the POS app, register the terminal, and confirm shift-related settings before front-of-house use.',
                'body_markdown' => 'The web app does not run the POS front end directly, but it does provide the install package and terminal setup screens.',
                'prerequisites' => ['A POS-enabled user account', 'A registered branch', 'Manager or terminal-management access'],
                'keywords' => ['pos', 'terminal', 'install app', 'shift'],
                'target_route' => 'settings.pos-terminals',
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'scoped',
                'allowed_roles' => ['admin', 'manager', 'cashier'],
                'allowed_permissions' => ['settings.pos_terminals.manage', 'pos.login'],
                'sort_order' => 30,
                'steps' => [
                    [
                        'title' => 'Download the POS app from the Tools section when the APK is available.',
                        'body_markdown' => 'The **Install POS App** link downloads the mobile installer file maintained by your team.',
                    ],
                    [
                        'title' => 'Open POS terminal settings to confirm branch, code, and device readiness.',
                        'body_markdown' => 'Make sure each terminal is assigned correctly before staff start their shifts.',
                        'image_key' => 'pos-install-and-shift.install',
                        'cta_label' => 'Open POS Terminals',
                        'cta_route' => 'settings.pos-terminals',
                    ],
                    [
                        'title' => 'Use POS-enabled credentials for login.',
                        'body_markdown' => 'A user may be active in RMS-1 but still blocked from POS if POS access is not enabled for that account.',
                    ],
                    [
                        'title' => 'For shift issues, verify permissions and terminal registration first.',
                        'body_markdown' => 'Most shift-start problems come from user access, branch mismatch, or terminal registration gaps.',
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'Why can a user log into RMS-1 but not POS?',
                        'answer_markdown' => 'The user also needs POS access and a compatible role or permission.',
                    ],
                    [
                        'question' => 'Where do I manage terminals?',
                        'answer_markdown' => 'Use **Settings > POS Terminals** if your account can manage terminal setup.',
                    ],
                    [
                        'question' => 'What if the install link returns not found?',
                        'answer_markdown' => 'The APK file may not have been uploaded yet to the expected storage location. Ask the administrator to upload the current build.',
                    ],
                ],
            ],
            [
                'title' => 'Creating and Managing Customers',
                'slug' => 'manage-customers',
                'module' => 'customers',
                'summary' => 'Create customer records, search existing accounts, and keep status and contact details up to date.',
                'body_markdown' => 'Use the Customers module for master customer data used across orders and receivables.',
                'prerequisites' => ['Receivables or management access'],
                'keywords' => ['customers', 'customer create', 'customer edit'],
                'target_route' => 'customers.index',
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'scoped',
                'allowed_roles' => ['admin', 'manager', 'cashier'],
                'allowed_permissions' => ['receivables.access'],
                'sort_order' => 40,
                'steps' => [
                    [
                        'title' => 'Open the Customers list to search before creating a duplicate.',
                        'body_markdown' => 'Use name, phone, email, or code search from the top filter bar.',
                        'image_key' => 'customers.list',
                        'cta_label' => 'Open Customers',
                        'cta_route' => 'customers.index',
                    ],
                    [
                        'title' => 'Use filters to narrow by customer type or active status.',
                        'body_markdown' => 'This is useful when checking inactive or corporate customer records.',
                    ],
                    [
                        'title' => 'Click **Create Customer** and fill the required profile fields.',
                        'body_markdown' => 'For corporate accounts, also review credit limit, payment terms, and billing details.',
                        'image_key' => 'customers.create',
                        'cta_label' => 'Create Customer',
                        'cta_route' => 'customers.create',
                    ],
                    [
                        'title' => 'Use the edit action later to update details or deactivate the record.',
                        'body_markdown' => 'Only deactivate a customer when it should no longer be used for new orders or invoices.',
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'Why should I search before creating a new customer?',
                        'answer_markdown' => 'Duplicate customer records create reporting and receivables cleanup problems later.',
                    ],
                    [
                        'question' => 'Can cashiers create customers?',
                        'answer_markdown' => 'Cashiers may be able to view customers, but creation and editing are typically reserved for managers or receivables users.',
                    ],
                    [
                        'question' => 'What if a customer should no longer be selectable?',
                        'answer_markdown' => 'Deactivate the record instead of deleting it so historical transactions remain intact.',
                    ],
                ],
            ],
            [
                'title' => 'Creating and Managing Menu Items',
                'slug' => 'manage-menu-items',
                'module' => 'catalog',
                'summary' => 'Maintain menu items, prices, categories, and branch availability from the catalog screens.',
                'body_markdown' => 'Use this guide when a team member needs to add or update sellable items.',
                'prerequisites' => ['Catalog access', 'At least one category and active branch'],
                'keywords' => ['menu items', 'catalog', 'price update'],
                'target_route' => 'menu-items.index',
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'scoped',
                'allowed_roles' => ['admin', 'manager', 'cashier'],
                'allowed_permissions' => ['catalog.access'],
                'sort_order' => 50,
                'steps' => [
                    [
                        'title' => 'Open Menu Items to review current active and inactive items.',
                        'body_markdown' => 'Use search, branch, category, and status filters to locate the item you need.',
                        'image_key' => 'menu-items.list',
                        'cta_label' => 'Open Menu Items',
                        'cta_route' => 'menu-items.index',
                    ],
                    [
                        'title' => 'Use **Categorize Items** or availability screens when the issue is not the core item record.',
                        'body_markdown' => 'Branch availability and categorization can be adjusted without recreating the item.',
                    ],
                    [
                        'title' => 'Click **Create** to add a new item with code, name, unit, price, and branch assignments.',
                        'body_markdown' => 'If the item should appear in ordering screens, make sure it is active and assigned to the right branches.',
                        'image_key' => 'menu-items.create',
                        'cta_label' => 'Create Menu Item',
                        'cta_route' => 'menu-items.create',
                    ],
                    [
                        'title' => 'Edit existing items for pricing, recipe links, or activation status.',
                        'body_markdown' => 'Avoid creating a second item when you only need a price or branch change.',
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'Why does a menu item not appear in Orders?',
                        'answer_markdown' => 'The item may be inactive or not assigned to the selected branch.',
                    ],
                    [
                        'question' => 'Should I create a new item for every price change?',
                        'answer_markdown' => 'No. Edit the existing item unless the business intentionally needs a separate sellable item.',
                    ],
                    [
                        'question' => 'What if I need to reorganize many items by category?',
                        'answer_markdown' => 'Use the categorization screen instead of editing each item one by one.',
                    ],
                ],
            ],
            [
                'title' => 'Recipe CRUD and Production',
                'slug' => 'recipe-crud-and-production',
                'module' => 'catalog',
                'summary' => 'Create recipes, manage ingredient rows and sub-recipes, review costing, and record production from the Recipes module.',
                'body_markdown' => 'Use this guide for backoffice recipe maintenance and production recording. Recipes stay linked to menu items and inventory costs, so changes should be made carefully.',
                'prerequisites' => ['Catalog access', 'Menu items available for linking', 'Inventory items available for ingredients'],
                'keywords' => ['recipes', 'recipe create', 'sub recipe', 'produce recipe', 'recipe costing'],
                'target_route' => 'recipes.index',
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'scoped',
                'allowed_roles' => ['admin', 'manager'],
                'allowed_permissions' => ['catalog.access'],
                'sort_order' => 55,
                'steps' => [
                    [
                        'title' => 'Open Recipes to search existing records before creating a duplicate.',
                        'body_markdown' => 'Use the search and category filter first. Published recipes can also be produced directly from this screen.',
                        'image_key' => 'recipes.index',
                        'cta_label' => 'Open Recipes',
                        'cta_route' => 'recipes.index',
                    ],
                    [
                        'title' => 'Create the recipe with the linked menu item, yield, overhead, and ingredient rows.',
                        'body_markdown' => 'Fill the recipe name, menu-item linkage, yield quantity, and ingredient lines. Ingredient rows can use either inventory items or sub-recipes depending on the composition.',
                        'image_key' => 'recipes.create',
                        'cta_label' => 'Create Recipe',
                        'cta_route' => 'recipes.create',
                    ],
                    [
                        'title' => 'Review the recipe detail page for ingredients, sub-recipes, costing, and draft or published status.',
                        'body_markdown' => 'Use **Show** and **Edit** to verify costing, nested sub-recipe paths, and whether the recipe is still a draft or already published for operational use.',
                        'image_key' => 'recipes.show',
                    ],
                    [
                        'title' => 'Use Produce from the recipe list when you need to record a production batch and update stock.',
                        'body_markdown' => 'Only published recipes expose the **Produce** action. Enter the produced quantity, production date, reference, and notes before saving the batch.',
                        'image_key' => 'recipes.produce',
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'When should I use a sub-recipe instead of an inventory item?',
                        'answer_markdown' => 'Use a sub-recipe when the ingredient itself is produced from another recipe. Use inventory items for direct raw-material lines.',
                    ],
                    [
                        'question' => 'Why is Produce not visible on some recipes?',
                        'answer_markdown' => 'The Produce action is intended for non-draft recipes. Publish the recipe first if it is ready for operational use.',
                    ],
                    [
                        'question' => 'Can I change the menu item link after a recipe is created?',
                        'answer_markdown' => 'Yes, but review costing and menu usage carefully before changing a live recipe-to-menu-item relationship.',
                    ],
                ],
            ],
            [
                'title' => 'Publishing and Cloning Daily Dish Menus',
                'slug' => 'publish-and-clone-daily-dish-menus',
                'module' => 'daily_dish',
                'summary' => 'Prepare a branch-specific Daily Dish menu, edit draft items, and publish when it is ready for operations.',
                'body_markdown' => 'Daily Dish menus are managed by branch and service date.',
                'prerequisites' => ['Operations access', 'Available menu items for the branch'],
                'keywords' => ['daily dish', 'menu publish', 'clone menu'],
                'target_route' => 'daily-dish.menus.index',
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'scoped',
                'allowed_roles' => ['admin', 'manager', 'kitchen'],
                'allowed_permissions' => ['operations.access'],
                'sort_order' => 60,
                'steps' => [
                    [
                        'title' => 'Open the Daily Dish calendar for the branch and service month.',
                        'body_markdown' => 'Review draft, published, or empty dates before making changes.',
                        'image_key' => 'daily-dish.index',
                        'cta_label' => 'Open Daily Dish',
                        'cta_route' => 'daily-dish.menus.index',
                    ],
                    [
                        'title' => 'Create or edit the target day while the menu is still in draft.',
                        'body_markdown' => 'Add the required menu items and keep notes in the draft until the selection is final.',
                    ],
                    [
                        'title' => 'Use clone when a new day should start from a similar existing menu.',
                        'body_markdown' => 'Cloning is faster than rebuilding repeated menus from scratch.',
                    ],
                    [
                        'title' => 'Publish only after the draft is complete.',
                        'body_markdown' => 'Published menus are the operational reference for Daily Dish order handling.',
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'Why can’t I publish a menu?',
                        'answer_markdown' => 'The menu must still be in draft and should contain at least one item.',
                    ],
                    [
                        'question' => 'When should I use clone instead of edit?',
                        'answer_markdown' => 'Use clone when the next service date should start from an existing menu structure.',
                    ],
                    [
                        'question' => 'Who should manage Daily Dish menus?',
                        'answer_markdown' => 'Managers and operations users are the usual owners, with kitchen users viewing operational outcomes.',
                    ],
                ],
            ],
            [
                'title' => 'Meal Plan Requests Review and Conversion',
                'slug' => 'meal-plan-requests-review-and-conversion',
                'module' => 'subscriptions',
                'summary' => 'Review incoming meal plan requests, update request status, and convert planned requests into subscriptions without losing the linked orders.',
                'body_markdown' => 'Meal Plan Requests are the pre-subscription intake workflow. Planned requests can be converted into subscriptions, while no-plan requests can be accepted without conversion.',
                'prerequisites' => ['Operations access', 'Customers and branches available for conversion'],
                'keywords' => ['meal plan request', 'convert to subscription', 'accept request', 'linked orders'],
                'target_route' => 'meal-plan-requests.index',
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'scoped',
                'allowed_roles' => ['admin', 'manager'],
                'allowed_permissions' => ['operations.access'],
                'sort_order' => 65,
                'steps' => [
                    [
                        'title' => 'Open Meal Plan Requests and filter by status to review the current queue.',
                        'body_markdown' => 'Use the status filter to separate new requests from contacted, converted, or closed requests before you take action.',
                        'image_key' => 'meal-plan-requests.index',
                        'cta_label' => 'Open Meal Plan Requests',
                        'cta_route' => 'meal-plan-requests.index',
                    ],
                    [
                        'title' => 'Open the request detail page to review plan size, contact details, notes, and linked orders.',
                        'body_markdown' => 'Check whether the request includes a plan and confirm that the related orders match what will later be converted or accepted.',
                        'image_key' => 'meal-plan-requests.show',
                    ],
                    [
                        'title' => 'Use Converted on planned requests to open the subscription conversion modal.',
                        'body_markdown' => 'The conversion flow lets you choose or create the customer, set the branch and start date, define weekdays and preferences, and attach the existing request orders to the new subscription.',
                        'image_key' => 'meal-plan-requests.convert',
                    ],
                    [
                        'title' => 'Use Accept for no-plan requests, or jump to the resulting subscription after conversion.',
                        'body_markdown' => 'No-plan requests can be accepted without creating a subscription. Converted requests should be reviewed again from the linked subscription page after the workflow finishes.',
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'When should I use Accept instead of Converted?',
                        'answer_markdown' => 'Use **Accept** when the request has no meal plan. Use **Converted** when the request should become a subscription.',
                    ],
                    [
                        'question' => 'Can I attach existing request orders to the subscription?',
                        'answer_markdown' => 'Yes. The conversion modal includes the option to attach and reclassify the existing linked orders.',
                    ],
                    [
                        'question' => 'What if the customer does not exist yet?',
                        'answer_markdown' => 'Use the conversion modal to create the customer during the conversion flow after confirming the request details.',
                    ],
                ],
            ],
            [
                'title' => 'Meal Subscriptions Lifecycle',
                'slug' => 'meal-subscriptions-lifecycle',
                'module' => 'subscriptions',
                'summary' => 'Create subscriptions, maintain schedules and preferences, manage pause or cancel actions, and generate subscription orders when needed.',
                'body_markdown' => 'Subscriptions are the recurring meal workflow owned by operations. Use this guide for creation, review, lifecycle updates, and manual generation.',
                'prerequisites' => ['Operations access', 'Customer and branch records available'],
                'keywords' => ['subscriptions', 'pause subscription', 'resume subscription', 'generate subscription orders'],
                'target_route' => 'subscriptions.index',
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'scoped',
                'allowed_roles' => ['admin', 'manager'],
                'allowed_permissions' => ['operations.access'],
                'sort_order' => 66,
                'steps' => [
                    [
                        'title' => 'Open Meal Subscriptions to review active, paused, cancelled, and expired records.',
                        'body_markdown' => 'Use the filters for status, branch, active date, and search to find the exact subscription before making changes.',
                        'image_key' => 'subscriptions.index',
                        'cta_label' => 'Open Subscriptions',
                        'cta_route' => 'subscriptions.index',
                    ],
                    [
                        'title' => 'Create the subscription with customer, branch, weekdays, plan size, and preference settings.',
                        'body_markdown' => 'Set the order type, delivery time, preferred role, salad and dessert options, and weekly schedule before saving the new subscription.',
                        'image_key' => 'subscriptions.create',
                        'cta_label' => 'Create Subscription',
                        'cta_route' => 'subscriptions.create',
                    ],
                    [
                        'title' => 'Use the subscription detail page to review schedule, pauses, linked customer data, and lifecycle actions.',
                        'body_markdown' => 'Pause, resume, and cancel are all controlled from the subscription detail page. Review the existing pauses and plan usage before taking action.',
                        'image_key' => 'subscriptions.show',
                    ],
                    [
                        'title' => 'Use Generate Subscription Orders when you need to run manual order generation.',
                        'body_markdown' => 'The generation screen is the operational fallback when orders need to be generated outside the normal automated workflow.',
                        'image_key' => 'subscriptions.generate',
                        'cta_label' => 'Generate Orders',
                        'cta_route' => 'subscriptions.generate',
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'What is the difference between paused and cancelled?',
                        'answer_markdown' => 'Paused subscriptions can later resume on schedule. Cancelled subscriptions stop future generation permanently.',
                    ],
                    [
                        'question' => 'When should I use a limited meal plan size?',
                        'answer_markdown' => 'Use a plan size when the subscription is sold as a fixed meal package such as 20 or 26 meals rather than unlimited service.',
                    ],
                    [
                        'question' => 'Do I always need the generate screen?',
                        'answer_markdown' => 'No. It is mainly for controlled manual generation or recovery scenarios when the normal recurring process is not enough.',
                    ],
                ],
            ],
            [
                'title' => 'Company Food Project Administration',
                'slug' => 'company-food-project-administration',
                'module' => 'company_food',
                'summary' => 'Create and manage Company Food projects, maintain menu options and employee lists, review orders, and use the export and print tools.',
                'body_markdown' => "This guide covers the internal backoffice administration screens only.\n\nIt does **not** document the public employee ordering flow, public API endpoints, or edit-token flows.",
                'prerequisites' => ['Operations access', 'A defined project date range', 'At least one employee list for the project'],
                'keywords' => ['company food', 'project admin', 'employee lists', 'menu options', 'company food orders'],
                'target_route' => 'company-food.projects.index',
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'scoped',
                'allowed_roles' => ['admin', 'manager'],
                'allowed_permissions' => ['operations.access'],
                'sort_order' => 67,
                'steps' => [
                    [
                        'title' => 'Open Company Food Projects to review existing projects and create new ones.',
                        'body_markdown' => 'Use the project list to filter active and inactive records before opening or editing a specific project.',
                        'image_key' => 'company-food.projects.index',
                        'cta_label' => 'Open Company Food Projects',
                        'cta_route' => 'company-food.projects.index',
                    ],
                    [
                        'title' => 'Create the project with name, company, date range, slug, and active status.',
                        'body_markdown' => 'The slug is used by the public API and external website integration, but this guide only covers the internal admin setup screen.',
                        'image_key' => 'company-food.projects.create',
                        'cta_label' => 'Create Project',
                        'cta_route' => 'company-food.projects.create',
                    ],
                    [
                        'title' => 'Use the project Orders tab to review submissions and access export or print actions.',
                        'body_markdown' => 'The Orders tab is the internal admin view of submitted employee selections and the starting point for employee and kitchen export workflows.',
                        'image_key' => 'company-food.projects.show-orders',
                    ],
                    [
                        'title' => 'Use the Menu tab to manage date-based options and open the menu drawer for a specific day.',
                        'body_markdown' => 'Daily Company Food options are maintained from the Menu tab. Use the day cards and menu drawer to add or edit date-specific options.',
                        'image_key' => 'company-food.projects.menu',
                    ],
                    [
                        'title' => 'Use the Lists tab to manage employee lists, categories, imports, and employee membership.',
                        'body_markdown' => 'Employee lists control which employees and category sets are available for the project. Maintain the lists before troubleshooting missing employee options.',
                        'image_key' => 'company-food.projects.lists',
                    ],
                    [
                        'title' => 'Use Edit when the project date range, slug, or active status changes.',
                        'body_markdown' => 'Edit the project instead of creating a duplicate when only the setup details need to change.',
                        'image_key' => 'company-food.projects.edit',
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'Does this guide cover the public employee ordering API?',
                        'answer_markdown' => 'No. This Help Center guide is limited to backoffice project administration. Public employee ordering and API usage are out of scope here.',
                    ],
                    [
                        'question' => 'Where do I manage daily menu options?',
                        'answer_markdown' => 'Use the **Menu** tab inside the project and open the menu drawer for the day you want to manage.',
                    ],
                    [
                        'question' => 'Where do I manage employees and employee lists?',
                        'answer_markdown' => 'Use the **Lists** tab inside the project to manage lists, categories, employee imports, and employee assignments.',
                    ],
                ],
            ],
            [
                'title' => 'Inventory Items and Stock Adjustments',
                'slug' => 'inventory-items-and-stock-adjustments',
                'module' => 'inventory',
                'summary' => 'Maintain inventory items and make controlled stock changes from the inventory screens.',
                'body_markdown' => 'Use Inventory for item setup, current stock visibility, and manual movement records.',
                'prerequisites' => ['Operations access'],
                'keywords' => ['inventory', 'stock', 'adjustment'],
                'target_route' => 'inventory.index',
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'scoped',
                'allowed_roles' => ['admin', 'manager', 'cashier'],
                'allowed_permissions' => ['operations.access'],
                'sort_order' => 70,
                'steps' => [
                    [
                        'title' => 'Open Inventory to search for the item you need.',
                        'body_markdown' => 'Start in the list before creating a second item with a similar name.',
                        'image_key' => 'inventory.index',
                        'cta_label' => 'Open Inventory',
                        'cta_route' => 'inventory.index',
                    ],
                    [
                        'title' => 'Create a new inventory item only when the stock item does not already exist.',
                        'body_markdown' => 'Define item code, unit of measure, supplier linkage, and stock thresholds.',
                        'cta_label' => 'Create Inventory Item',
                        'cta_route' => 'inventory.create',
                    ],
                    [
                        'title' => 'Use the item detail or transactions screen to post adjustments.',
                        'body_markdown' => 'Record the reason clearly so inventory history remains auditable.',
                    ],
                    [
                        'title' => 'Verify the branch-level stock impact after the adjustment.',
                        'body_markdown' => 'If a branch has no stock record yet, make sure the item is stocked in the correct branch before using it operationally.',
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'Why should stock adjustments include notes?',
                        'answer_markdown' => 'Notes help explain the business reason behind the stock movement during audits and reconciliations.',
                    ],
                    [
                        'question' => 'Can I use a transfer instead of an adjustment?',
                        'answer_markdown' => 'Use transfers when stock is moving between branches. Use adjustments when stock changes for one branch only.',
                    ],
                    [
                        'question' => 'What if the inventory item already exists but with outdated details?',
                        'answer_markdown' => 'Edit the existing item instead of creating a duplicate record.',
                    ],
                ],
            ],
            [
                'title' => 'Inventory Transfers',
                'slug' => 'inventory-transfers',
                'module' => 'inventory',
                'summary' => 'Move stock between branches using the transfer screen so the movement is visible in both locations.',
                'body_markdown' => 'Transfers are the controlled way to move stock across branches.',
                'prerequisites' => ['Operations access', 'Source and destination branches'],
                'keywords' => ['transfer', 'branch stock transfer', 'inventory move'],
                'target_route' => 'inventory.transfers',
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'scoped',
                'allowed_roles' => ['admin', 'manager'],
                'allowed_permissions' => ['operations.access'],
                'sort_order' => 80,
                'steps' => [
                    [
                        'title' => 'Open the Transfers screen from Inventory.',
                        'body_markdown' => 'Confirm the source branch before selecting items.',
                        'image_key' => 'inventory.transfers',
                        'cta_label' => 'Open Transfers',
                        'cta_route' => 'inventory.transfers',
                    ],
                    [
                        'title' => 'Set source and destination branches correctly.',
                        'body_markdown' => 'A transfer affects both sides of the stock movement, so branch selection matters.',
                    ],
                    [
                        'title' => 'Add the item lines and quantities to transfer.',
                        'body_markdown' => 'Search for the item, review the quantity, and add a reason or note if your process requires it.',
                    ],
                    [
                        'title' => 'Submit the transfer and verify the transaction history.',
                        'body_markdown' => 'Use inventory history or reports if you need to confirm that the movement posted successfully.',
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'When should I use a transfer instead of an adjustment?',
                        'answer_markdown' => 'Use a transfer when stock leaves one branch and arrives at another. Use an adjustment for a one-branch correction.',
                    ],
                    [
                        'question' => 'Why is branch selection mandatory?',
                        'answer_markdown' => 'The system has to know both the source and destination stock buckets.',
                    ],
                    [
                        'question' => 'What if the item search returns nothing?',
                        'answer_markdown' => 'Check that the inventory item exists and is active for transfer use.',
                    ],
                ],
            ],
            [
                'title' => 'Purchase Orders Workflow',
                'slug' => 'purchase-orders-workflow',
                'module' => 'purchasing',
                'summary' => 'Create, submit, approve, and receive purchase orders from the purchasing workflow screens.',
                'body_markdown' => 'Purchase Orders connect purchasing operations with receiving and downstream finance activity.',
                'prerequisites' => ['Operations, finance, or management access', 'Suppliers and inventory items in the system'],
                'keywords' => ['purchase order', 'po', 'receive po'],
                'target_route' => 'purchase-orders.index',
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'scoped',
                'allowed_roles' => ['admin', 'manager', 'staff'],
                'allowed_permissions' => ['operations.access', 'finance.access'],
                'sort_order' => 90,
                'steps' => [
                    [
                        'title' => 'Open the Purchase Orders list to review current draft and submitted POs.',
                        'body_markdown' => 'Search and filters help you find the exact PO before creating a new one.',
                        'image_key' => 'purchase-orders.index',
                        'cta_label' => 'Open Purchase Orders',
                        'cta_route' => 'purchase-orders.index',
                    ],
                    [
                        'title' => 'Click **Create** and select the supplier first.',
                        'body_markdown' => 'Then add inventory lines and expected delivery details.',
                        'cta_label' => 'Create Purchase Order',
                        'cta_route' => 'purchase-orders.create',
                    ],
                    [
                        'title' => 'Review quantities, costs, and notes before submission.',
                        'body_markdown' => 'A clean draft reduces approval and receiving issues later.',
                        'image_key' => 'purchase-orders.create',
                    ],
                    [
                        'title' => 'Submit, approve, and receive the PO according to your internal workflow.',
                        'body_markdown' => 'Receiving should only happen when the actual goods and quantities have been checked.',
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'Why should I search before creating a PO?',
                        'answer_markdown' => 'A PO may already exist in draft or pending status for the same supplier and request.',
                    ],
                    [
                        'question' => 'Who can approve purchase orders?',
                        'answer_markdown' => 'That depends on your assigned roles and permissions. Managers are typical approvers.',
                    ],
                    [
                        'question' => 'When should receiving be done?',
                        'answer_markdown' => 'Only after the actual items and quantities have been verified physically.',
                    ],
                ],
            ],
            [
                'title' => 'AR Invoices and Customer Payments',
                'slug' => 'ar-invoices-and-customer-payments',
                'module' => 'receivables',
                'summary' => 'Create AR invoices, review issued documents, and record customer payments with proper allocation.',
                'body_markdown' => 'Use the Receivables area for customer billing and payment collection workflows.',
                'prerequisites' => ['Receivables access', 'Customers and orders in the system'],
                'keywords' => ['invoice', 'customer payment', 'receivables'],
                'target_route' => 'invoices.index',
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'scoped',
                'allowed_roles' => ['admin', 'manager'],
                'allowed_permissions' => ['receivables.access'],
                'sort_order' => 100,
                'steps' => [
                    [
                        'title' => 'Open the Invoices list to review draft, issued, and paid invoices.',
                        'body_markdown' => 'Check whether the invoice already exists before creating a new one.',
                        'image_key' => 'receivables.invoices',
                        'cta_label' => 'Open Invoices',
                        'cta_route' => 'invoices.index',
                    ],
                    [
                        'title' => 'Create the invoice from the invoice screen or from orders ready to invoice.',
                        'body_markdown' => 'Confirm customer, payment terms, invoice lines, and issue details.',
                        'cta_label' => 'Orders to Invoice',
                        'cta_route' => 'receivables.orders-to-invoice',
                    ],
                    [
                        'title' => 'Issue the invoice when it is complete and ready to send or record.',
                        'body_markdown' => 'Issued invoices become part of receivables reporting and customer statements.',
                    ],
                    [
                        'title' => 'Record customer payments and allocate them to the right invoice or advance balance.',
                        'body_markdown' => 'Use the Customer Payments area when money is received.',
                        'cta_label' => 'Customer Payments',
                        'cta_route' => 'receivables.payments.index',
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'Should I create a second invoice if one already exists in draft?',
                        'answer_markdown' => 'No. Reopen the draft and complete it unless the business case truly needs a separate invoice.',
                    ],
                    [
                        'question' => 'Where do I apply customer payments?',
                        'answer_markdown' => 'Use the **Customer Payments** section in Receivables.',
                    ],
                    [
                        'question' => 'Why is invoice status important?',
                        'answer_markdown' => 'Status controls whether the invoice is only a draft or already part of live receivables.',
                    ],
                ],
            ],
            [
                'title' => 'Submitting and Processing Spend Requests',
                'slug' => 'submit-and-process-spend-requests',
                'module' => 'spend',
                'summary' => 'Use the Spend hub for staged submission, approval, posting, and settlement of expense-related workflows.',
                'body_markdown' => 'Spend is the replacement workflow for the legacy expenses flow.',
                'prerequisites' => ['Finance or staff access'],
                'keywords' => ['spend', 'expense approval', 'settle expense'],
                'target_route' => 'spend.index',
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'scoped',
                'allowed_roles' => ['admin', 'manager', 'staff'],
                'allowed_permissions' => ['finance.access'],
                'sort_order' => 110,
                'steps' => [
                    [
                        'title' => 'Open the Spend hub and choose the relevant tab or queue.',
                        'body_markdown' => 'This is the main workspace for draft, submitted, approved, and settled expense flows.',
                        'image_key' => 'spend.index',
                        'cta_label' => 'Open Spend',
                        'cta_route' => 'spend.index',
                    ],
                    [
                        'title' => 'Create or review the expense record and attach supporting details.',
                        'body_markdown' => 'Provide enough supplier, category, and amount detail for the next approver.',
                    ],
                    [
                        'title' => 'Submit the request for approval.',
                        'body_markdown' => 'Manager and finance stages depend on your configured workflow and assigned permissions.',
                    ],
                    [
                        'title' => 'Post and settle only after approvals and finance checks are complete.',
                        'body_markdown' => 'Settlement should follow the real payment or petty-cash process.',
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'Is the old Expenses area still active?',
                        'answer_markdown' => 'No. RMS-1 redirects legacy expenses screens to the Spend workflow.',
                    ],
                    [
                        'question' => 'Who can approve spend requests?',
                        'answer_markdown' => 'Managers and finance users, depending on the stage and their permissions.',
                    ],
                    [
                        'question' => 'When should settlement be done?',
                        'answer_markdown' => 'Only after the expense has been posted and the actual payment process is ready to be recorded.',
                    ],
                ],
            ],
            [
                'title' => 'Running and Exporting Reports',
                'slug' => 'run-and-export-reports',
                'module' => 'reports',
                'summary' => 'Open the report hub, choose the right report category, apply filters, and export when needed.',
                'body_markdown' => 'Reports are grouped by category and support screen, print, CSV, and PDF outputs depending on the report.',
                'prerequisites' => ['Reports access'],
                'keywords' => ['reports', 'export csv', 'pdf report'],
                'target_route' => 'reports.index',
                'locale' => 'en',
                'status' => 'published',
                'visibility_mode' => 'scoped',
                'allowed_roles' => ['admin', 'manager', 'staff'],
                'allowed_permissions' => ['reports.access'],
                'sort_order' => 120,
                'steps' => [
                    [
                        'title' => 'Open the Reports hub and choose the category first.',
                        'body_markdown' => 'Categories help narrow the report list quickly.',
                        'image_key' => 'reports.index',
                        'cta_label' => 'Open Reports',
                        'cta_route' => 'reports.index',
                    ],
                    [
                        'title' => 'Select the report that matches the business question.',
                        'body_markdown' => 'Review the listed filters and output options before opening it.',
                    ],
                    [
                        'title' => 'Apply date, branch, customer, supplier, or status filters as needed.',
                        'body_markdown' => 'Good filters reduce cleanup work after export.',
                    ],
                    [
                        'title' => 'Use screen, print, CSV, or PDF output depending on the report.',
                        'body_markdown' => 'Not every report offers the same output combination, so check the controls on that report page.',
                    ],
                ],
                'faqs' => [
                    [
                        'question' => 'Where do I start if I do not know which report to use?',
                        'answer_markdown' => 'Start in the Reports hub and choose the category closest to the business area you are working on.',
                    ],
                    [
                        'question' => 'Do all reports support CSV and PDF?',
                        'answer_markdown' => 'No. Output options depend on the specific report implementation.',
                    ],
                    [
                        'question' => 'Why are filters important before exporting?',
                        'answer_markdown' => 'Applying filters before export keeps the output smaller, more accurate, and easier to share.',
                    ],
                ],
            ],
        ];
    }
}

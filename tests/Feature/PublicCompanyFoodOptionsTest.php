<?php

use App\Models\CompanyFoodEmployee;
use App\Models\CompanyFoodListCategory;
use App\Models\CompanyFoodOption;
use App\Models\CompanyFoodProject;
use Illuminate\Support\Str;

function createCompanyFoodProject(): CompanyFoodProject
{
    return CompanyFoodProject::create([
        'name' => 'Project '.Str::random(8),
        'company_name' => 'Layla Kitchen',
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-31',
        'slug' => 'cf-'.Str::lower(Str::random(12)),
        'is_active' => true,
    ]);
}

test('public options includes soup for list when soup options exist for that list/date', function () {
    $project = createCompanyFoodProject();
    $list = $project->employeeLists()->firstOrFail();
    CompanyFoodListCategory::query()
        ->where('employee_list_id', $list->id)
        ->where('category', 'soup')
        ->delete();

    CompanyFoodEmployee::create([
        'project_id' => $project->id,
        'employee_list_id' => $list->id,
        'employee_name' => 'John Smith',
        'sort_order' => 1,
    ]);

    $menuDate = '2026-03-10';

    CompanyFoodOption::create([
        'project_id' => $project->id,
        'employee_list_id' => $list->id,
        'menu_date' => $menuDate,
        'category' => 'main',
        'name' => 'Grilled Chicken',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $soup = CompanyFoodOption::create([
        'project_id' => $project->id,
        'employee_list_id' => $list->id,
        'menu_date' => $menuDate,
        'category' => 'soup',
        'name' => 'Lentil Soup',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $response = $this->getJson("/api/public/company-food/{$project->slug}/options")
        ->assertOk()
        ->json();

    $lists = collect($response['data']['options_by_date'][$menuDate]['lists'] ?? []);
    $listPayload = $lists->firstWhere('id', $list->id);

    expect($listPayload)->not->toBeNull();
    expect($listPayload['categories'])->toHaveKey('soup');
    expect($listPayload['categories']['soup'])->toHaveCount(1);
    expect((int) ($listPayload['categories']['soup'][0]['id'] ?? 0))->toBe((int) $soup->id);
});

test('public order create requires soup when list/date has active soup options', function () {
    $project = createCompanyFoodProject();
    $list = $project->employeeLists()->firstOrFail();

    // Simulate a list missing soup in list settings while soup options are configured in menu.
    CompanyFoodListCategory::query()
        ->where('employee_list_id', $list->id)
        ->delete();
    CompanyFoodListCategory::create([
        'employee_list_id' => $list->id,
        'category' => 'main',
        'sort_order' => 1,
    ]);

    CompanyFoodEmployee::create([
        'project_id' => $project->id,
        'employee_list_id' => $list->id,
        'employee_name' => 'John Smith',
        'sort_order' => 1,
    ]);

    $menuDate = '2026-03-10';

    $main = CompanyFoodOption::create([
        'project_id' => $project->id,
        'employee_list_id' => $list->id,
        'menu_date' => $menuDate,
        'category' => 'main',
        'name' => 'Grilled Chicken',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $soup = CompanyFoodOption::create([
        'project_id' => $project->id,
        'employee_list_id' => $list->id,
        'menu_date' => $menuDate,
        'category' => 'soup',
        'name' => 'Lentil Soup',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $this->postJson("/api/public/company-food/{$project->slug}/orders", [
        'order_date' => $menuDate,
        'employee_list_id' => $list->id,
        'employee_name' => 'John Smith',
        'main_option_id' => $main->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['soup_option_id']);

    $this->postJson("/api/public/company-food/{$project->slug}/orders", [
        'order_date' => $menuDate,
        'employee_list_id' => $list->id,
        'employee_name' => 'John Smith',
        'main_option_id' => $main->id,
        'soup_option_id' => $soup->id,
    ])->assertCreated();
});


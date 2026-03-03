<?php

use App\Models\CompanyFoodEmployee;
use App\Models\CompanyFoodOption;
use App\Models\CompanyFoodOrder;
use App\Models\CompanyFoodProject;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function companyFoodAdmin(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::firstOrCreate(['name' => 'admin']);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

test('admin can download company food employee list pdf export', function () {
    $user = companyFoodAdmin();
    $project = CompanyFoodProject::create([
        'name' => 'AHR Meals 2026',
        'company_name' => 'AHR',
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-10',
        'slug' => 'ahr-meals-2026',
        'is_active' => true,
    ]);

    $list = $project->employeeLists()->firstOrFail();
    CompanyFoodEmployee::create([
        'project_id' => $project->id,
        'employee_list_id' => $list->id,
        'employee_name' => 'John Smith',
        'sort_order' => 1,
    ]);

    $response = $this->actingAs($user)->get(route('company-food.projects.export-employees-pdf', $project));

    $response->assertOk();
    expect((string) $response->headers->get('content-type'))->toStartWith('application/pdf');
    expect((string) $response->headers->get('content-disposition'))
        ->toContain('company-food-employees-'.$project->slug.'.pdf');
});

test('admin can download company food kitchen prep pdf export', function () {
    $user = companyFoodAdmin();
    $project = CompanyFoodProject::create([
        'name' => 'QIFF Meals 2026',
        'company_name' => 'QIFF',
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-10',
        'slug' => 'qiff-meals-2026',
        'is_active' => true,
    ]);

    $list = $project->employeeLists()->firstOrFail();
    $menuDate = '2026-03-02';

    CompanyFoodEmployee::create([
        'project_id' => $project->id,
        'employee_list_id' => $list->id,
        'employee_name' => 'Jane Doe',
        'sort_order' => 1,
    ]);

    $optionData = [
        ['category' => 'salad', 'name' => 'Fattoush'],
        ['category' => 'appetizer', 'name' => 'Hummus'],
        ['category' => 'main', 'name' => 'Chicken Kebab'],
        ['category' => 'sweet', 'name' => 'Baklava'],
        ['category' => 'location', 'name' => 'Office A'],
        ['category' => 'soup', 'name' => 'Lentil Soup'],
    ];

    $optionsByCategory = collect($optionData)->mapWithKeys(function (array $row, int $index) use ($project, $list, $menuDate) {
        $option = CompanyFoodOption::create([
            'project_id' => $project->id,
            'employee_list_id' => $list->id,
            'menu_date' => $menuDate,
            'category' => $row['category'],
            'name' => $row['name'],
            'sort_order' => $index + 1,
            'is_active' => true,
        ]);

        return [$row['category'] => $option];
    });

    CompanyFoodOrder::create([
        'project_id' => $project->id,
        'employee_list_id' => $list->id,
        'order_date' => $menuDate,
        'employee_name' => 'Jane Doe',
        'email' => 'jane@company.test',
        'salad_option_id' => $optionsByCategory['salad']->id,
        'appetizer_option_id_1' => $optionsByCategory['appetizer']->id,
        'appetizer_option_id_2' => $optionsByCategory['appetizer']->id,
        'main_option_id' => $optionsByCategory['main']->id,
        'sweet_option_id' => $optionsByCategory['sweet']->id,
        'location_option_id' => $optionsByCategory['location']->id,
        'soup_option_id' => $optionsByCategory['soup']->id,
    ]);

    $response = $this->actingAs($user)->get(route('company-food.projects.export-kitchen-prep-pdf', $project));

    $response->assertOk();
    expect((string) $response->headers->get('content-type'))->toStartWith('application/pdf');
    expect((string) $response->headers->get('content-disposition'))
        ->toContain('company-food-kitchen-prep-'.$project->slug.'.pdf');
});

test('company food csv export applies order date filter', function () {
    $user = companyFoodAdmin();
    $project = CompanyFoodProject::create([
        'name' => 'CSV Filter Project',
        'company_name' => 'Filter Co',
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-10',
        'slug' => 'csv-filter-project',
        'is_active' => true,
    ]);

    $list = $project->employeeLists()->firstOrFail();

    CompanyFoodOrder::create([
        'project_id' => $project->id,
        'employee_list_id' => $list->id,
        'order_date' => '2026-03-02',
        'employee_name' => 'Filtered Employee',
        'email' => 'filtered@company.test',
    ]);

    CompanyFoodOrder::create([
        'project_id' => $project->id,
        'employee_list_id' => $list->id,
        'order_date' => '2026-03-03',
        'employee_name' => 'Excluded Employee',
        'email' => 'excluded@company.test',
    ]);

    $response = $this->actingAs($user)->get(route('company-food.projects.export-csv', [
        'project' => $project,
        'order_date' => '2026-03-02',
    ]));

    $response->assertOk();
    expect((string) $response->headers->get('content-type'))->toContain('text/csv');
    expect((string) $response->headers->get('content-disposition'))
        ->toContain('company-food-orders-'.$project->slug.'.csv');

    $csv = $response->streamedContent();
    expect($csv)->toContain('Filtered Employee');
    expect($csv)->not->toContain('Excluded Employee');
});

test('company food csv export is sorted by employee list order, not employee name', function () {
    $user = companyFoodAdmin();
    $project = CompanyFoodProject::create([
        'name' => 'CSV Order Project',
        'company_name' => 'Order Co',
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-10',
        'slug' => 'csv-order-project',
        'is_active' => true,
    ]);

    $listOne = $project->employeeLists()->firstOrFail();
    $listOne->update([
        'name' => 'Z List',
        'sort_order' => 0,
    ]);

    $listTwo = $project->employeeLists()->create([
        'name' => 'A List',
        'sort_order' => 1,
    ]);

    CompanyFoodEmployee::create([
        'project_id' => $project->id,
        'employee_list_id' => $listOne->id,
        'employee_name' => 'Zed Employee',
        'sort_order' => 1,
    ]);
    CompanyFoodEmployee::create([
        'project_id' => $project->id,
        'employee_list_id' => $listTwo->id,
        'employee_name' => 'Adam Employee',
        'sort_order' => 1,
    ]);

    CompanyFoodOrder::create([
        'project_id' => $project->id,
        'employee_list_id' => $listOne->id,
        'order_date' => '2026-03-02',
        'employee_name' => 'Zed Employee',
        'email' => 'zed@company.test',
    ]);
    CompanyFoodOrder::create([
        'project_id' => $project->id,
        'employee_list_id' => $listTwo->id,
        'order_date' => '2026-03-02',
        'employee_name' => 'Adam Employee',
        'email' => 'adam@company.test',
    ]);

    $response = $this->actingAs($user)->get(route('company-food.projects.export-csv', [
        'project' => $project,
        'order_date' => '2026-03-02',
    ]));

    $response->assertOk();
    $csv = $response->streamedContent();

    expect(strpos($csv, 'Zed Employee'))->toBeLessThan(strpos($csv, 'Adam Employee'));
});

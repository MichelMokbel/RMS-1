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

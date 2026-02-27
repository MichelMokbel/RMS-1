<?php

namespace Database\Seeders;

use App\Models\CompanyFoodEmployeeList;
use App\Models\CompanyFoodListCategory;
use App\Models\CompanyFoodOption;
use App\Models\CompanyFoodProject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class CompanyFoodOptionsSeeder extends Seeder
{
    public function run(): void
    {
        $project = CompanyFoodProject::firstOrCreate(
            ['slug' => 'ahr-meals-2026'],
            [
                'name' => 'AHR Meals 2026',
                'company_name' => 'Layla Kitchen',
                'start_date' => '2026-02-18',
                'end_date' => '2026-03-19',
                'is_active' => true,
            ]
        );

        $listOne = $project->employeeLists()->orderBy('sort_order')->first();
        if (! $listOne) {
            $listOne = CompanyFoodEmployeeList::create([
                'project_id' => $project->id,
                'name' => 'List 1',
                'sort_order' => 0,
            ]);
        }

        $listTwo = CompanyFoodEmployeeList::firstOrCreate(
            [
                'project_id' => $project->id,
                'name' => 'List 2',
            ],
            [
                'sort_order' => 1,
            ]
        );

        $this->ensureListCategories($listOne);
        $this->ensureListCategories($listTwo);

        $dates = [
            '2026-02-18',
            '2026-02-19',
            '2026-02-20',
            '2026-02-21',
            '2026-02-22',
        ];

        $shared = [
            'salad' => ['Caesar Salad', 'Greek Salad', 'Fattoush'],
            'appetizer' => ['Hummus', 'Stuffed Vine Leaves', 'Spring Rolls'],
            'sweet' => ['Baklava', 'Fruit Cup', 'Date Pudding'],
            'location' => ['HQ Office', 'Site A', 'Site B'],
        ];

        $listSpecific = [
            $listOne->id => [
                'main' => ['Grilled Chicken', 'Beef Stroganoff', 'Baked Fish'],
                'soup' => ['Lentil Soup', 'Tomato Soup', 'Mushroom Soup'],
            ],
            $listTwo->id => [
                'main' => ['Vegetable Pasta', 'Lamb Biryani', 'Paneer Curry'],
                'soup' => ['Chicken Noodle Soup', 'Pumpkin Soup', 'Minestrone'],
            ],
        ];

        foreach ($dates as $date) {
            $dayIndex = CarbonImmutable::parse($date)->dayOfYear;

            foreach ($shared as $category => $names) {
                $rotated = $this->rotate($names, $dayIndex);
                foreach ($rotated as $sort => $name) {
                    CompanyFoodOption::updateOrCreate(
                        [
                            'project_id' => $project->id,
                            'employee_list_id' => $listOne->id,
                            'menu_date' => $date,
                            'category' => $category,
                            'name' => $name,
                        ],
                        [
                            'sort_order' => $sort + 1,
                            'is_active' => true,
                        ]
                    );
                }
            }

            foreach ($listSpecific as $listId => $cats) {
                foreach ($cats as $category => $names) {
                    $rotated = $this->rotate($names, $dayIndex);
                    foreach ($rotated as $sort => $name) {
                        CompanyFoodOption::updateOrCreate(
                            [
                                'project_id' => $project->id,
                                'employee_list_id' => $listId,
                                'menu_date' => $date,
                                'category' => $category,
                                'name' => $name,
                            ],
                            [
                                'sort_order' => $sort + 1,
                                'is_active' => true,
                            ]
                        );
                    }
                }
            }
        }
    }

    private function ensureListCategories(CompanyFoodEmployeeList $list): void
    {
        foreach (CompanyFoodOption::CATEGORIES as $sort => $category) {
            CompanyFoodListCategory::firstOrCreate(
                [
                    'employee_list_id' => $list->id,
                    'category' => $category,
                ],
                [
                    'sort_order' => $sort,
                ]
            );
        }
    }

    /**
     * @param  array<int, string>  $items
     * @return array<int, string>
     */
    private function rotate(array $items, int $seed): array
    {
        $count = count($items);
        if ($count === 0) {
            return [];
        }

        $offset = $seed % $count;

        return array_merge(
            array_slice($items, $offset),
            array_slice($items, 0, $offset)
        );
    }
}

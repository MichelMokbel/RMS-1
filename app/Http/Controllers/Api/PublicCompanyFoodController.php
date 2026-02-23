<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyFoodOption;
use App\Models\CompanyFoodProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicCompanyFoodController extends Controller
{
    public function options(Request $request, string $projectSlug): JsonResponse
    {
        $project = CompanyFoodProject::query()
            ->where('slug', $projectSlug)
            ->where('is_active', true)
            ->with(['employeeLists.listCategories', 'employeeLists.employees'])
            ->firstOrFail();

        $allOptions = $project->activeOptions()->orderBy('menu_date')->orderBy('category')->orderBy('sort_order')->get();
        $dates = $allOptions->pluck('menu_date')->unique()->map(fn ($d) => $d->format('Y-m-d'))->values()->all();

        $listSpecificCategories = ['main', 'soup'];

        $optionsByDate = [];
        foreach ($allOptions->groupBy(fn ($o) => $o->menu_date->format('Y-m-d')) as $dateStr => $dateOptions) {
            $lists = $project->employeeLists->map(function ($list) use ($dateOptions, $listSpecificCategories) {
                $configuredCategories = $list->listCategories->pluck('category')
                    ->map(fn ($c) => (string) $c)
                    ->filter()
                    ->values()
                    ->all();

                $orderedConfigured = array_values(array_filter(
                    CompanyFoodOption::CATEGORIES,
                    fn (string $cat): bool => in_array($cat, $configuredCategories, true)
                ));

                $itemsByCategory = [];
                $dynamicCategories = [];
                foreach (CompanyFoodOption::CATEGORIES as $cat) {
                    $items = $dateOptions->where('category', $cat);
                    if (in_array($cat, $listSpecificCategories, true)) {
                        $items = $items->where('employee_list_id', $list->id);
                    }

                    $itemsByCategory[$cat] = $items;
                    if ($items->isNotEmpty()) {
                        $dynamicCategories[] = $cat;
                    }
                }

                $effectiveCategories = array_values(array_unique([
                    ...$orderedConfigured,
                    ...$dynamicCategories,
                ]));

                $categories = [];
                foreach ($effectiveCategories as $cat) {
                    $items = $itemsByCategory[$cat] ?? collect();
                    $categories[$cat] = $items->map(fn ($opt) => [
                        'id' => $opt->id,
                        'name' => $opt->name,
                    ])->values()->all();
                }

                return [
                    'id' => $list->id,
                    'name' => $list->name,
                    'employees' => $list->employees->map(fn ($e) => ['name' => $e->employee_name])->values()->all(),
                    'categories' => $categories,
                ];
            })->values()->all();

            $optionsByDate[$dateStr] = ['lists' => $lists];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'project_start' => $project->start_date->format('Y-m-d'),
                'project_end' => $project->end_date->format('Y-m-d'),
                'available_dates' => $dates,
                'options_by_date' => $optionsByDate,
            ],
        ]);
    }
}

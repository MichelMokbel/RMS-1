<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Recipe;
use App\Services\Recipes\RecipeCostingService;
use App\Support\Reports\CsvExport;
use App\Support\Reports\PdfExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CostingReportController extends Controller
{
    private function query(Request $request, int $limit = 500)
    {
        return Recipe::query()
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->search.'%'))
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    public function print(Request $request, RecipeCostingService $costingService)
    {
        $recipes = $this->query($request);
        $costingByRecipe = [];
        foreach ($recipes as $recipe) {
            try {
                $costingByRecipe[$recipe->id] = $costingService->compute($recipe);
            } catch (\Throwable $e) {
                $costingByRecipe[$recipe->id] = null;
            }
        }
        $filters = $request->only(['category_id', 'search']);

        return view('reports.costing-print', ['recipes' => $recipes, 'costingByRecipe' => $costingByRecipe, 'filters' => $filters, 'generatedAt' => now()]);
    }

    public function csv(Request $request, RecipeCostingService $costingService): StreamedResponse
    {
        $recipes = $this->query($request, 500);
        $headers = [__('Recipe'), __('Base Cost'), __('Total Cost'), __('Cost/Unit'), __('Selling Price'), __('Margin %')];
        $rows = [];
        foreach ($recipes as $recipe) {
            try {
                $c = $costingService->compute($recipe);
                $rows[] = [
                    $recipe->name,
                    number_format($c['base_cost_total'], 3, '.', ''),
                    number_format($c['total_cost_with_overhead'], 3, '.', ''),
                    number_format($c['cost_per_yield_unit_display'], 3, '.', ''),
                    $c['selling_price_per_unit'] !== null ? number_format($c['selling_price_per_unit'], 3, '.', '') : '',
                    $c['margin_pct'] !== null ? number_format($c['margin_pct'] * 100, 1, '.', '') : '',
                ];
            } catch (\Throwable $e) {
                $rows[] = [$recipe->name, '', '', '', '', ''];
            }
        }

        return CsvExport::stream($headers, $rows, 'costing-report.csv');
    }

    public function pdf(Request $request, RecipeCostingService $costingService)
    {
        $recipes = $this->query($request);
        $costingByRecipe = [];
        foreach ($recipes as $recipe) {
            try {
                $costingByRecipe[$recipe->id] = $costingService->compute($recipe);
            } catch (\Throwable $e) {
                $costingByRecipe[$recipe->id] = null;
            }
        }
        $filters = $request->only(['category_id', 'search']);

        return PdfExport::download('reports.costing-print', ['recipes' => $recipes, 'costingByRecipe' => $costingByRecipe, 'filters' => $filters, 'generatedAt' => now()], 'costing-report.pdf');
    }
}

<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\Menu\MenuItemUsageService;
use App\Services\Storefront\StorefrontAdministrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StorefrontCleanupExportController extends Controller
{
    public function __invoke(
        Request $request,
        StorefrontAdministrationService $administration,
        MenuItemUsageService $usage,
    ) {
        $payload = $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $companyId = $administration->companyIdFor($actor);
        $items = MenuItem::query()
            ->when(filled($payload['search'] ?? null), fn ($query) => $query->search($payload['search']))
            ->orderBy('id')
            ->get();

        return response()->streamDownload(function () use ($items, $usage, $companyId): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'id', 'code', 'name', 'active', 'recipe_id', 'branch_ids', 'storefront_state',
                'total_references', 'orders', 'daily_dish_menus', 'pastry_orders', 'quotations',
                'order_sheets', 'sales', 'invoices', 'storefront_profiles', 'payment_target_items',
                'recommended_action',
            ]);
            foreach ($items as $item) {
                $report = $usage->usageReport((int) $item->id);
                $profileStates = DB::table('storefront_item_profiles')
                    ->where('company_id', $companyId)
                    ->where('menu_item_id', $item->id)
                    ->orderBy('branch_id')
                    ->get(['branch_id', 'direct_order_enabled'])
                    ->map(fn ($profile): string => 'branch '.$profile->branch_id.':'.($profile->direct_order_enabled ? 'direct' : 'hidden'))
                    ->implode('|');
                $branchIds = DB::table('menu_item_branches')->where('menu_item_id', $item->id)->orderBy('branch_id')->pluck('branch_id')->implode('|');
                $references = $report['references'];
                fputcsv($stream, [
                    $item->id,
                    $item->code,
                    $item->name,
                    $item->is_active ? 'yes' : 'no',
                    $item->recipe_id,
                    $branchIds,
                    $profileStates ?: 'not configured',
                    $report['total_references'],
                    ...array_values($references),
                    (int) $report['total_references'] === 0 ? 'review for permanent deletion' : 'disable and hide if not sellable',
                ]);
            }
            fclose($stream);
        }, 'storefront-catalog-cleanup-'.now('Asia/Qatar')->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}

<?php

namespace App\Http\Controllers\MenuItems;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuItem;
use App\Support\Reports\CsvExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MenuItemsExportController extends Controller
{
    private function query(Request $request)
    {
        $search     = (string) $request->input('search', '');
        $status     = (string) $request->input('status', 'active');
        $categoryId = $request->filled('category_id') ? $request->integer('category_id') : null;
        $branchId   = $request->filled('branch_id')   ? $request->integer('branch_id')   : null;

        return MenuItem::query()
            ->with('category')
            ->search($search)
            ->when($status !== 'all', fn ($q) => $q->where('is_active', $status === 'active'))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->availableInBranch($branchId)
            ->ordered()
            ->get();
    }

    private function unitLabel(?string $unit): string
    {
        return MenuItem::unitOptions()[$unit ?? 'each'] ?? ($unit ?? '—');
    }

    public function print(Request $request)
    {
        $items = $this->query($request);

        $categoryId = $request->filled('category_id') ? $request->integer('category_id') : null;
        $status     = (string) $request->input('status', 'active');

        $categoryName = $categoryId
            ? (Category::find($categoryId)?->name ?? $categoryId)
            : 'All';

        $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;
        $branchName = $branchId
            ? (Schema::hasTable('branches') ? (string) (DB::table('branches')->where('id', $branchId)->value('name') ?: $branchId) : $branchId)
            : 'All';

        return view('reports.menu-items-print', [
            'items'        => $items,
            'categoryName' => $categoryName,
            'branchName'   => $branchName,
            'statusLabel'  => ucfirst($status),
            'generatedAt'  => now(),
            'generatedBy'  => $request->user()?->username ?: $request->user()?->name ?: '-',
            'unitLabel'    => fn ($u) => $this->unitLabel($u),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $items = $this->query($request);

        $headers = ['Code', 'Name', 'Arabic Name', 'Category', 'Unit', 'Price', 'Active'];

        $rows = $items->map(fn ($item) => [
            $item->code,
            $item->name,
            $item->arabic_name,
            $item->category?->name ?? '',
            $this->unitLabel($item->unit),
            number_format((float) $item->selling_price_per_unit, 3),
            $item->is_active ? 'Active' : 'Inactive',
        ]);

        return CsvExport::stream($headers, $rows, 'menu-items.csv');
    }
}

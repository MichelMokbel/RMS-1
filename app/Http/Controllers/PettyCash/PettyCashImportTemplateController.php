<?php

namespace App\Http\Controllers\PettyCash;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Models\PettyCashWallet;
use App\Models\Supplier;
use App\Services\Accounting\AccountingContextService;
use App\Services\PettyCash\PettyCashImportTemplateBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PettyCashImportTemplateController extends Controller
{
    public function __invoke(
        Request $request,
        AccountingContextService $context,
        PettyCashImportTemplateBuilder $builder
    ): BinaryFileResponse {
        abort_unless($request->user()?->hasRole('admin') && $request->user()?->can('petty_cash.import'), 403);

        $companyId = $context->resolveCompanyId();
        abort_unless($companyId, 422, __('A default accounting company must be configured before downloading the template.'));

        $suppliers = Supplier::query()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('hold_status')->orWhere('hold_status', 'open');
            })
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Supplier $supplier): string => $supplier->id.' | '.$supplier->name)
            ->all();
        $categories = ExpenseCategory::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (ExpenseCategory $category): string => $category->id.' | '.$category->name)
            ->all();
        $wallets = PettyCashWallet::query()
            ->where('active', true)
            ->orderBy('driver_name')
            ->get(['id', 'driver_id', 'driver_name'])
            ->map(fn (PettyCashWallet $wallet): string => $wallet->id.' | '.($wallet->driver_name ?: __('Custodian :id', ['id' => $wallet->driver_id])))
            ->all();

        $bulk = $request->query('mode') === 'bulk';
        $path = $bulk
            ? $builder->buildBulk($suppliers, $categories, $wallets)
            : $builder->build($suppliers, $categories, $wallets);

        return response()->download($path, $bulk ? 'petty-cash-multiple-date-import-template.xlsx' : 'petty-cash-daily-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}

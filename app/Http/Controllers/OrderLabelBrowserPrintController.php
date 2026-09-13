<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PastryOrder;
use App\Models\User;
use App\Services\Orders\OrderLabelService;
use App\Services\Security\BranchAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OrderLabelBrowserPrintController extends Controller
{
    public function show(Request $request, OrderLabelService $labels, string $sourceType, int $sourceId): Response
    {
        $validated = $request->validate([
            'profile_id' => ['required', 'integer', 'min:1'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);
        $document = $labels->browserDocument(
            $this->actor($request),
            $sourceType,
            [$sourceId],
            (int) $validated['profile_id'],
            (int) ($validated['copies'] ?? 1),
        );

        return $this->view($document);
    }

    public function batch(Request $request, OrderLabelService $labels, BranchAccessService $branchAccess): Response
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'source_type' => ['required', 'in:order,pastry_order'],
            'profile_id' => ['required', 'integer', 'min:1'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:10'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        abort_unless($branchAccess->canAccessBranch($this->actor($request), (int) $validated['branch_id']), 403);
        $model = $validated['source_type'] === 'pastry_order' ? PastryOrder::class : Order::class;
        $sourceIds = $model::query()
            ->where('branch_id', (int) $validated['branch_id'])
            ->whereDate('scheduled_date', $validated['date'])
            ->where('status', '!=', 'Cancelled')
            ->when(trim((string) ($validated['search'] ?? '')) !== '', function (Builder $query) use ($validated): void {
                $term = '%'.trim((string) $validated['search']).'%';
                $query->where(fn (Builder $nested) => $nested
                    ->where('order_number', 'like', $term)
                    ->orWhere('customer_name_snapshot', 'like', $term));
            })
            ->orderByRaw('CASE WHEN scheduled_time IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_time')
            ->orderBy('id')
            ->limit(200)
            ->pluck('id')
            ->all();
        $document = $labels->browserDocument(
            $this->actor($request),
            (string) $validated['source_type'],
            $sourceIds,
            (int) $validated['profile_id'],
            (int) ($validated['copies'] ?? 1),
        );

        return $this->view($document);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function view(array $document): Response
    {
        return response()->view('prints.order-label-browser', $document)
            ->header('Cache-Control', 'private, no-store')
            ->header('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'self'");
    }
}

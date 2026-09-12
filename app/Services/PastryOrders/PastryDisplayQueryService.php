<?php

namespace App\Services\PastryOrders;

use App\Models\Branch;
use App\Models\PastryOrder;
use App\Models\User;
use App\Services\Security\BranchAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PastryDisplayQueryService
{
    public function __construct(
        private readonly BranchAccessService $branchAccess,
        private readonly PastryOrderImageService $images,
    ) {}

    /** @return Collection<int, Branch> */
    public function availableBranches(User $actor): Collection
    {
        $query = Branch::query()
            ->where('is_active', true)
            ->orderBy('name');

        $this->branchAccess->applyBranchScope($query, $actor, 'id');

        return $query->get(['id', 'name']);
    }

    public function assertCanView(User $actor, int $branchId): void
    {
        abort_unless(
            $actor->hasAnyRole(['admin', 'manager']) || $actor->can('pastry.display'),
            403
        );
        abort_unless($this->branchAccess->canAccessBranch($actor, $branchId), 403);
        abort_unless(
            Branch::query()->whereKey($branchId)->where('is_active', true)->exists(),
            404
        );
    }

    /** @return Collection<int, array<string, mixed>> */
    public function ordersForDay(User $actor, int $branchId, string $serviceDate): Collection
    {
        $this->assertCanView($actor, $branchId);

        return $this->safeQuery()
            ->where('branch_id', $branchId)
            ->whereDate('scheduled_date', $serviceDate)
            ->where('status', '!=', 'Cancelled')
            ->orderByRaw('CASE WHEN scheduled_time IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_time')
            ->orderBy('id')
            ->get()
            ->map(fn (PastryOrder $order): array => $this->toDisplayArray($order));
    }

    /** @return array<string, mixed>|null */
    public function orderForDay(User $actor, int $branchId, string $serviceDate, int $orderId): ?array
    {
        $this->assertCanView($actor, $branchId);

        $order = $this->safeQuery()
            ->whereKey($orderId)
            ->where('branch_id', $branchId)
            ->whereDate('scheduled_date', $serviceDate)
            ->where('status', '!=', 'Cancelled')
            ->first();

        return $order ? $this->toDisplayArray($order) : null;
    }

    private function safeQuery(): Builder
    {
        return PastryOrder::query()
            ->select([
                'id',
                'order_number',
                'branch_id',
                'status',
                'type',
                'customer_name_snapshot',
                'delivery_address_snapshot',
                'scheduled_date',
                'scheduled_time',
                'notes',
            ])
            ->with([
                'items' => fn ($query) => $query
                    ->select(['id', 'pastry_order_id', 'description_snapshot', 'quantity', 'sort_order'])
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'images' => fn ($query) => $query
                    ->select(['id', 'pastry_order_id', 'image_path', 'image_disk', 'sort_order'])
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ]);
    }

    /** @return array<string, mixed> */
    private function toDisplayArray(PastryOrder $order): array
    {
        return [
            'id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'status' => (string) $order->status,
            'type' => (string) $order->type,
            'customer_name' => (string) ($order->customer_name_snapshot ?: __('Customer')),
            'destination' => $order->delivery_address_snapshot ? (string) $order->delivery_address_snapshot : null,
            'scheduled_date' => $order->scheduled_date?->toDateString(),
            'scheduled_time' => $order->scheduled_time ? (string) $order->scheduled_time : null,
            'notes' => $order->notes ? (string) $order->notes : null,
            'items' => $order->items->map(fn ($item): array => [
                'description' => (string) $item->description_snapshot,
                'quantity' => (string) $item->quantity,
            ])->values()->all(),
            'images' => $this->images->presignedUrlsForOrder($order),
        ];
    }
}

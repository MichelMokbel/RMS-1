<?php

namespace App\Services\Subscriptions;

use App\Models\Branch;
use App\Models\MealSubscriptionOrder;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use App\Services\Payments\PaymentCheckoutException;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class MembershipBookingReadService
{
    public function __construct(
        private readonly AccountingContextService $accountingContext,
        private readonly MembershipQueueService $queues,
    ) {}

    public function index(
        User $user,
        int $selectedBranchId,
        string $queueReference,
        string $filter = 'future',
        int $perPage = 20,
    ): LengthAwarePaginator {
        if (! (bool) config('payments.membership.queue_enabled', false)) {
            throw new PaymentCheckoutException('MEMBERSHIP_QUEUE_DISABLED', 503, __('Membership access is not available yet.'));
        }
        $companyId = (int) $this->accountingContext->defaultCompanyId();
        $branchId = (int) config('payments.public_order_branch_id', 1);
        $branch = Branch::query()->find($branchId);
        if ($selectedBranchId !== $branchId || $companyId <= 0 || ! $branch?->is_active
            || (int) $branch->company_id !== $companyId) {
            throw ValidationException::withMessages(['selected_branch_id' => __('This membership branch is unavailable.')]);
        }

        $roots = $this->queues->compatibleRootsForRead((int) $user->customer_id, $companyId, $branchId);
        $root = $roots->sortBy([['created_at', 'asc'], ['id', 'asc']])->first();
        if (! $root || ! hash_equals((string) $root->subscription_code, trim($queueReference))) {
            throw new PaymentCheckoutException('MEMBERSHIP_BOOKING_NOT_FOUND', 404, __('Membership booking was not found.'));
        }

        $today = CarbonImmutable::now('Asia/Qatar')->toDateString();
        $rootIds = $roots->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $query = MealSubscriptionOrder::query()
            ->with(['order.items.menuItem', 'funding.invoice'])
            ->whereIn('subscription_id', $rootIds)
            ->whereNotNull('booking_uuid')
            ->whereRaw(
                'meal_subscription_orders.booking_revision = ('
                .'SELECT MAX(latest_booking.booking_revision) FROM meal_subscription_orders latest_booking '
                .'WHERE latest_booking.booking_uuid = meal_subscription_orders.booking_uuid '
                .'AND latest_booking.subscription_id IN ('.implode(',', array_fill(0, count($rootIds), '?')).')'
                .')',
                $rootIds,
            )
            ->when($filter === 'future', fn ($builder) => $builder->whereDate('service_date', '>', $today))
            ->when($filter === 'history', fn ($builder) => $builder->whereDate('service_date', '<=', $today))
            ->orderBy('service_date')
            ->orderBy('id');

        return $query->paginate(min(50, max(1, $perPage)))->through(fn (MealSubscriptionOrder $mapping): array => $this->present($mapping));
    }

    /** @return array<string, mixed> */
    private function present(MealSubscriptionOrder $mapping): array
    {
        $activeFunding = $mapping->funding->whereIn('state', ['reserved', 'invoiced']);
        $scheduled = $mapping->order?->status !== 'Cancelled' && $activeFunding->isNotEmpty();
        $deadlineRow = $mapping->funding->sortBy('id')->first();
        $deadline = $deadlineRow?->change_deadline_at
            ? CarbonImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $deadlineRow->change_deadline_at->format('Y-m-d H:i:s'),
                (string) $deadlineRow->booking_timezone,
            )
            : null;
        $canChange = $scheduled && $deadline && CarbonImmutable::now($deadline->getTimezone())->lessThan($deadline);
        $invoice = $mapping->funding->pluck('invoice')->filter()->first();
        $items = $mapping->order?->items ?? collect();

        return [
            'booking_reference' => (string) $mapping->booking_uuid,
            'booking_revision' => (int) $mapping->booking_revision,
            'service_date' => $mapping->service_date?->toDateString(),
            'status' => $scheduled ? 'scheduled' : 'cancelled',
            'main_quantity' => (int) $activeFunding->sum('main_quantity'),
            'change_deadline_at' => $deadline?->toIso8601String(),
            'can_change' => (bool) $canChange,
            'can_cancel' => (bool) $canChange,
            'action_reason' => $canChange ? null : ($scheduled ? __('The online change deadline has passed.') : __('This booking is cancelled.')),
            'order_number' => $mapping->order?->order_number,
            'invoice_number' => $invoice?->invoice_number,
            'invoice_status' => $invoice?->status,
            'selections' => [
                'mains' => $items->whereIn('role', ['main', 'diet', 'vegetarian'])->map(fn ($item): array => [
                    'menu_item_id' => (int) $item->menu_item_id,
                    'name' => (string) ($item->menuItem?->name ?? $item->description_snapshot),
                    'quantity' => (int) $item->quantity,
                ])->values()->all(),
                'included_sides' => $items->whereNotIn('role', ['main', 'diet', 'vegetarian'])->map(fn ($item): array => [
                    'menu_item_id' => (int) $item->menu_item_id,
                    'name' => (string) ($item->menuItem?->name ?? $item->description_snapshot),
                    'quantity' => (int) $item->quantity,
                    'role' => (string) $item->role,
                ])->values()->all(),
            ],
        ];
    }
}

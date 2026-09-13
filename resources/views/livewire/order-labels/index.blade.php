<?php

use App\Models\Order;
use App\Models\OrderLabelPrinterProfile;
use App\Models\PastryOrder;
use App\Models\User;
use App\Services\Security\BranchAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branchId = 0;
    public string $date = '';
    public string $sourceType = 'order';
    public int $profileId = 0;
    public int $copies = 1;
    public string $search = '';

    public function mount(BranchAccessService $branches): void
    {
        $this->assertCanPrint();
        $this->date = now()->toDateString();
        $this->branchId = $branches->allowedBranchIds($this->actor())[0] ?? 0;
        $this->selectDefaultProfile();
    }

    public function updatedBranchId(): void
    {
        $this->selectDefaultProfile();
    }

    public function with(BranchAccessService $branchAccess): array
    {
        $actor = $this->actor();
        abort_unless($branchAccess->canAccessBranch($actor, $this->branchId), 403);
        $branchQuery = \App\Models\Branch::query()->where('is_active', true)->orderBy('name');
        $branchAccess->applyBranchScope($branchQuery, $actor, 'id');

        return [
            'branches' => $branchQuery->get(['id', 'name']),
            'profiles' => OrderLabelPrinterProfile::query()
                ->where('branch_id', $this->branchId)
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
            'orders' => $this->eligibleQuery()->limit(300)->get(),
        ];
    }

    public function batchPrintUrl(): ?string
    {
        if ($this->profileId <= 0 || $this->branchId <= 0) {
            return null;
        }

        return route('order-labels.print.batch', [
            'branch_id' => $this->branchId,
            'date' => $this->date,
            'source_type' => $this->sourceType,
            'profile_id' => $this->profileId,
            'copies' => max(1, min(10, $this->copies)),
            'search' => trim($this->search),
        ]);
    }

    public function printUrl(int $sourceId): ?string
    {
        if ($this->profileId <= 0) {
            return null;
        }

        return route('order-labels.print.show', [
            'sourceType' => $this->sourceType,
            'sourceId' => $sourceId,
            'profile_id' => $this->profileId,
            'copies' => max(1, min(10, $this->copies)),
        ]);
    }

    private function eligibleQuery(): Builder
    {
        $model = $this->sourceType === 'pastry_order' ? PastryOrder::class : Order::class;

        return $model::query()
            ->select(['id', 'order_number', 'branch_id', 'status', 'customer_name_snapshot', 'scheduled_date', 'scheduled_time'])
            ->where('branch_id', $this->branchId)
            ->whereDate('scheduled_date', $this->date)
            ->where('status', '!=', 'Cancelled')
            ->when($this->search !== '', function (Builder $query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(fn (Builder $nested) => $nested
                    ->where('order_number', 'like', $term)
                    ->orWhere('customer_name_snapshot', 'like', $term));
            })
            ->orderByRaw('CASE WHEN scheduled_time IS NULL THEN 1 ELSE 0 END')
            ->orderBy('scheduled_time')
            ->orderBy('id');
    }

    private function selectDefaultProfile(): void
    {
        $this->profileId = (int) (OrderLabelPrinterProfile::query()
            ->where('branch_id', $this->branchId)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->value('id') ?? 0);
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function assertCanPrint(): void
    {
        $actor = $this->actor();
        abort_unless($actor->hasAnyRole(['admin', 'manager']) || $actor->can('order-labels.print'), 403);
    }
}; ?>

<main class="app-page space-y-6">
    <header>
        <p class="text-sm font-semibold uppercase tracking-wide text-primary-700 dark:text-primary-300">{{ __('Production') }}</p>
        <h1 class="mt-1 text-3xl font-bold text-neutral-950 dark:text-white">{{ __('Order labels') }}</h1>
        <p class="mt-1 text-neutral-600 dark:text-neutral-300">{{ __('Choose an order and print directly from this device using the normal browser print window.') }}</p>
    </header>

    <section class="grid gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 md:grid-cols-2 xl:grid-cols-6">
        <div><label class="mb-1 block text-xs font-semibold uppercase">{{ __('Branch') }}</label><select wire:model.live="branchId" class="min-h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 dark:border-neutral-700 dark:bg-neutral-950">@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div>
        <div><label class="mb-1 block text-xs font-semibold uppercase">{{ __('Date') }}</label><input wire:model.live="date" type="date" class="min-h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 dark:border-neutral-700 dark:bg-neutral-950"></div>
        <div><label class="mb-1 block text-xs font-semibold uppercase">{{ __('Orders') }}</label><select wire:model.live="sourceType" class="min-h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 dark:border-neutral-700 dark:bg-neutral-950"><option value="order">{{ __('Ordinary orders') }}</option><option value="pastry_order">{{ __('Pastry orders') }}</option></select></div>
        <div><label class="mb-1 block text-xs font-semibold uppercase">{{ __('Label format') }}</label><select wire:model.live="profileId" class="min-h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 dark:border-neutral-700 dark:bg-neutral-950"><option value="0">{{ __('Choose a label format') }}</option>@foreach($profiles as $profile)<option value="{{ $profile->id }}">{{ $profile->name }} · {{ number_format($profile->width_tenths_mm / 10, 1) }}×{{ number_format(($profile->height_tenths_mm ?? 0) / 10, 1) }} mm</option>@endforeach</select></div>
        <div><label class="mb-1 block text-xs font-semibold uppercase">{{ __('Copies') }}</label><input wire:model.live="copies" type="number" min="1" max="10" class="min-h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 dark:border-neutral-700 dark:bg-neutral-950"></div>
        <div class="flex items-end">
            @if ($this->batchPrintUrl())
                <flux:button class="w-full" variant="primary" :href="$this->batchPrintUrl()" target="_blank">{{ __('Print all shown') }}</flux:button>
            @else
                <flux:button class="w-full" variant="primary" disabled>{{ __('Print all shown') }}</flux:button>
            @endif
        </div>
        <div class="md:col-span-2 xl:col-span-6"><flux:input wire:model.live.debounce.300ms="search" :label="__('Search order or customer')" /></div>
    </section>

    @if ($profiles->isEmpty())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900">{{ __('No label format exists for this branch. Add the 57 × 37 mm format in Settings first.') }}</div>
    @endif

    <section class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="divide-y divide-neutral-200 dark:divide-neutral-700">
            @forelse ($orders as $order)
                <article wire:key="label-order-{{ $sourceType }}-{{ $order->id }}" class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="font-bold text-neutral-950 dark:text-white">{{ $order->order_number }} · {{ $order->customer_name_snapshot }}</div>
                        <div class="mt-1 text-sm text-neutral-500">{{ $order->scheduled_time ? \Illuminate\Support\Carbon::parse($order->scheduled_time)->format('H:i') : __('No time') }}</div>
                    </div>
                    @if ($this->printUrl($order->id))
                        <flux:button size="sm" variant="primary" :href="$this->printUrl($order->id)" target="_blank">{{ __('Print label') }}</flux:button>
                    @else
                        <flux:button size="sm" variant="primary" disabled>{{ __('Print label') }}</flux:button>
                    @endif
                </article>
            @empty
                <div class="p-10 text-center text-neutral-500">{{ __('No eligible orders for this date.') }}</div>
            @endforelse
        </div>
    </section>
</main>

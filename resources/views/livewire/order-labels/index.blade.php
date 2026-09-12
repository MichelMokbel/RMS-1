<?php

use App\Models\Order;
use App\Models\OrderLabelPrint;
use App\Models\OrderLabelPrinterProfile;
use App\Models\PastryOrder;
use App\Models\User;
use App\Services\Orders\OrderLabelService;
use App\Services\Security\BranchAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branchId = 0;
    public string $date = '';
    public string $sourceType = 'order';
    public int $profileId = 0;
    public int $copies = 1;
    public string $search = '';
    public ?int $reprintLabelId = null;
    public string $reprintReason = '';

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
        $profiles = OrderLabelPrinterProfile::query()
            ->where('branch_id', $this->branchId)
            ->where('is_active', true)
            ->where('is_verified', true)
            ->with('terminal')
            ->orderBy('name')
            ->get();
        $orders = $this->eligibleQuery()->limit(300)->get();
        $history = OrderLabelPrint::query()
            ->where('branch_id', $this->branchId)
            ->whereDate('service_date', $this->date)
            ->where('source_type', $this->sourceType)
            ->with(['profile', 'printJob'])
            ->latest('id')
            ->limit(100)
            ->get()
            ->groupBy('source_id');

        return compact('profiles', 'orders', 'history') + ['branches' => $branchQuery->get(['id', 'name'])];
    }

    public function printOne(int $sourceId, OrderLabelService $service): void
    {
        $this->validateSelection();
        abort_unless($this->eligibleQuery()->whereKey($sourceId)->exists(), 404);
        $service->request(
            $this->actor(), $this->sourceType, $sourceId, $this->profileId, $this->copies, (string) Str::uuid()
        );
        session()->flash('status', __('Label queued.'));
    }

    public function printBatch(OrderLabelService $service): void
    {
        $this->validateSelection();
        $sourceIds = $this->eligibleQuery()->limit(200)->pluck('id');
        $alreadyPrinted = OrderLabelPrint::query()
            ->where('printer_profile_id', $this->profileId)
            ->where('source_type', $this->sourceType)
            ->whereIn('source_id', $sourceIds)
            ->pluck('source_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $queued = 0;
        foreach ($sourceIds as $sourceId) {
            if (in_array((int) $sourceId, $alreadyPrinted, true)) {
                continue;
            }
            $service->request(
                $this->actor(), $this->sourceType, (int) $sourceId, $this->profileId, $this->copies, (string) Str::uuid()
            );
            $queued++;
        }
        session()->flash('status', trans_choice(
            ':count label queued; previously printed orders were skipped.|:count labels queued; previously printed orders were skipped.',
            $queued,
            ['count' => $queued]
        ));
    }

    public function startReprint(int $labelId): void
    {
        $label = $this->scopedLabel($labelId);
        $this->reprintLabelId = (int) $label->id;
        $this->reprintReason = '';
    }

    public function cancelReprint(): void
    {
        $this->reprintLabelId = null;
        $this->reprintReason = '';
        $this->resetValidation('reprintReason');
    }

    public function confirmReprint(OrderLabelService $service): void
    {
        $this->validate(['reprintReason' => ['required', 'string', 'min:3', 'max:500']]);
        $label = $this->scopedLabel((int) $this->reprintLabelId);
        $service->request(
            $this->actor(),
            (string) $label->source_type,
            (int) $label->source_id,
            (int) $label->printer_profile_id,
            $this->copies,
            (string) Str::uuid(),
            (int) $label->id,
            $this->reprintReason,
        );
        $this->cancelReprint();
        session()->flash('status', __('Reprint queued with its reason recorded.'));
    }

    public function cancelLabel(int $labelId, OrderLabelService $service): void
    {
        $service->cancel($this->actor(), $this->scopedLabel($labelId));
        session()->flash('status', __('Queued label cancelled.'));
    }

    public function reassignLabel(int $labelId, OrderLabelService $service): void
    {
        $this->validateSelection();
        $profile = OrderLabelPrinterProfile::query()->findOrFail($this->profileId);
        $service->reassign($this->actor(), $this->scopedLabel($labelId), $profile);
        session()->flash('status', __('Queued label reassigned to the selected printer.'));
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

    private function scopedLabel(int $labelId): OrderLabelPrint
    {
        return OrderLabelPrint::query()
            ->whereKey($labelId)
            ->where('branch_id', $this->branchId)
            ->where('source_type', $this->sourceType)
            ->firstOrFail();
    }

    private function validateSelection(): void
    {
        $this->validate([
            'branchId' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'sourceType' => ['required', 'in:order,pastry_order'],
            'profileId' => ['required', 'integer', 'min:1'],
            'copies' => ['required', 'integer', 'min:1', 'max:10'],
        ]);
        abort_unless(OrderLabelPrinterProfile::query()
            ->whereKey($this->profileId)
            ->where('branch_id', $this->branchId)
            ->where('is_active', true)
            ->where('is_verified', true)
            ->exists(), 422);
        abort_unless(app(BranchAccessService::class)->canAccessBranch($this->actor(), $this->branchId), 403);
    }

    private function selectDefaultProfile(): void
    {
        $this->profileId = (int) (OrderLabelPrinterProfile::query()
            ->where('branch_id', $this->branchId)
            ->where('is_active', true)
            ->where('is_verified', true)
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
        <p class="mt-1 text-neutral-600 dark:text-neutral-300">{{ __('Queue one label or the unprinted orders for a service date. Reprints always require a reason.') }}</p>
    </header>

    @if (session('status'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif

    <section class="grid gap-3 rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 md:grid-cols-2 xl:grid-cols-6">
        <div><label class="mb-1 block text-xs font-semibold uppercase">{{ __('Branch') }}</label><select wire:model.live="branchId" class="min-h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 dark:border-neutral-700 dark:bg-neutral-950">@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></div>
        <div><label class="mb-1 block text-xs font-semibold uppercase">{{ __('Date') }}</label><input wire:model.live="date" type="date" class="min-h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 dark:border-neutral-700 dark:bg-neutral-950"></div>
        <div><label class="mb-1 block text-xs font-semibold uppercase">{{ __('Orders') }}</label><select wire:model.live="sourceType" class="min-h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 dark:border-neutral-700 dark:bg-neutral-950"><option value="order">{{ __('Ordinary orders') }}</option><option value="pastry_order">{{ __('Pastry orders') }}</option></select></div>
        <div><label class="mb-1 block text-xs font-semibold uppercase">{{ __('Printer') }}</label><select wire:model="profileId" class="min-h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 dark:border-neutral-700 dark:bg-neutral-950"><option value="0">{{ __('No active printer') }}</option>@foreach($profiles as $profile)<option value="{{ $profile->id }}">{{ $profile->name }}</option>@endforeach</select></div>
        <div><label class="mb-1 block text-xs font-semibold uppercase">{{ __('Copies') }}</label><input wire:model="copies" type="number" min="1" max="10" class="min-h-11 w-full rounded-lg border border-neutral-300 bg-white px-3 dark:border-neutral-700 dark:bg-neutral-950"></div>
        <div class="flex items-end"><flux:button class="w-full" variant="primary" wire:click="printBatch" :disabled="$profileId <= 0">{{ __('Print unprinted') }}</flux:button></div>
        <div class="md:col-span-2 xl:col-span-6"><flux:input wire:model.live.debounce.300ms="search" :label="__('Search order or customer')" /></div>
    </section>

    @if ($profiles->isEmpty())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900">{{ __('No verified active printer is available for this branch. Configure and test one in Settings first.') }}</div>
    @endif

    <section class="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <div class="divide-y divide-neutral-200 dark:divide-neutral-700">
            @forelse ($orders as $order)
                @php $latest = $history->get($order->id)?->first(); @endphp
                <article wire:key="label-order-{{ $sourceType }}-{{ $order->id }}" class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div class="font-bold text-neutral-950 dark:text-white">{{ $order->order_number }} · {{ $order->customer_name_snapshot }}</div>
                        <div class="mt-1 text-sm text-neutral-500">{{ $order->scheduled_time ? \Illuminate\Support\Carbon::parse($order->scheduled_time)->format('H:i') : __('No time') }} @if($latest) · {{ __('Label: :status', ['status' => $latest->status]) }} @endif</div>
                    </div>
                    <div class="flex gap-2">
                        @if ($latest)
                            <flux:button size="sm" variant="ghost" wire:click="startReprint({{ $latest->id }})">{{ __('Reprint') }}</flux:button>
                            @if ($latest->status === 'queued')
                                @if ((int) $latest->printer_profile_id !== $profileId && $profileId > 0)
                                    <flux:button size="sm" variant="ghost" wire:click="reassignLabel({{ $latest->id }})">{{ __('Move to selected printer') }}</flux:button>
                                @endif
                                <flux:button size="sm" variant="danger" wire:click="cancelLabel({{ $latest->id }})">{{ __('Cancel label') }}</flux:button>
                            @endif
                        @else
                            <flux:button size="sm" variant="primary" wire:click="printOne({{ $order->id }})" :disabled="$profileId <= 0">{{ __('Print label') }}</flux:button>
                        @endif
                    </div>
                </article>
            @empty
                <div class="p-10 text-center text-neutral-500">{{ __('No eligible orders for this date.') }}</div>
            @endforelse
        </div>
    </section>

    @if ($reprintLabelId)
        <div class="fixed inset-0 z-[99999] flex items-end justify-center bg-black/50 p-4 sm:items-center">
            <section role="dialog" aria-modal="true" class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl dark:bg-neutral-900">
                <h2 class="text-xl font-bold">{{ __('Why is this label being reprinted?') }}</h2>
                <p class="mt-1 text-sm text-neutral-500">{{ __('This reason is kept in the print history.') }}</p>
                <div class="mt-4"><flux:textarea wire:model="reprintReason" :label="__('Reprint reason')" rows="3" /></div>
                <div class="mt-5 flex justify-end gap-2"><flux:button variant="ghost" wire:click="cancelReprint">{{ __('Cancel') }}</flux:button><flux:button variant="primary" wire:click="confirmReprint">{{ __('Queue reprint') }}</flux:button></div>
            </section>
        </div>
    @endif
</main>

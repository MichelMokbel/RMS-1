<?php

use App\Models\MembershipPlan;
use App\Models\MembershipPromotion;
use App\Models\User;
use App\Services\Promotions\MembershipPromotionService;
use App\Services\Promotions\PromotionAccessService;
use App\Services\Promotions\PromotionUsageProjectionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $status = '';
    public string $plan = '';
    public string $eligibility = '';
    public string $discount_type = 'fixed';
    public string $fixed_amount = '';
    public string $percentage = '';
    public string $purchase_eligibility = 'both';
    public string $starts_on = '';
    public string $ends_on = '';
    public int|string $total_limit = 1;
    public int|string $per_customer_limit = 1;
    /** @var array<int, int|string> */
    public array $eligible_plan_ids = [];
    public string $operation_uuid = '';

    protected $paginationTheme = 'tailwind';

    protected $queryString = [
        'status' => ['except' => ''],
        'plan' => ['except' => ''],
        'eligibility' => ['except' => ''],
    ];

    public function mount(PromotionAccessService $access): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $companyId = $access->companyIdFor($actor);
        $this->starts_on = now('Asia/Qatar')->toDateString();
        $this->ends_on = now('Asia/Qatar')->addMonth()->toDateString();
        $this->eligible_plan_ids = MembershipPlan::query()
            ->where('company_id', $companyId)
            ->whereIn('code', ['20', '26'])
            ->where('is_active', true)
            ->orderBy('meal_count')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $this->operation_uuid = (string) Str::uuid();
    }

    public function updatingStatus(): void { $this->resetPage(); }
    public function updatingPlan(): void { $this->resetPage(); }
    public function updatingEligibility(): void { $this->resetPage(); }

    public function createPromotion(PromotionAccessService $access, MembershipPromotionService $service): mixed
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $access->companyIdFor($actor);

        $promotion = $service->create($actor, [
            'discount_type' => $this->discount_type,
            'fixed_amount' => $this->fixed_amount,
            'percentage' => $this->percentage,
            'purchase_eligibility' => $this->purchase_eligibility,
            'starts_on' => $this->starts_on,
            'ends_on' => $this->ends_on,
            'total_limit' => $this->total_limit,
            'per_customer_limit' => $this->per_customer_limit,
            'eligible_plan_ids' => $this->eligible_plan_ids,
        ], $this->operation_uuid);

        $this->operation_uuid = (string) Str::uuid();
        session()->flash('status', __('Promotion draft created. Review it before activation.'));

        return $this->redirectRoute('membership-promotions.show', ['promotion' => $promotion->id], navigate: true);
    }

    public function with(PromotionAccessService $access, PromotionUsageProjectionService $usage): array
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $companyId = $access->companyIdFor($actor);
        $plans = MembershipPlan::query()
            ->where('company_id', $companyId)
            ->whereIn('code', ['20', '26'])
            ->where('is_active', true)
            ->orderBy('meal_count')
            ->get();
        $now = now('UTC');
        $promotions = MembershipPromotion::query()
            ->with('plans')
            ->where('company_id', $companyId)
            ->when($this->status === 'draft', fn ($query) => $query->where('status', 'draft'))
            ->when($this->status === 'live', fn ($query) => $query->where('status', 'active')->where('starts_at', '<=', $now)->where('ends_at', '>', $now))
            ->when($this->status === 'scheduled', fn ($query) => $query->where('status', 'active')->where('starts_at', '>', $now)->where('ends_at', '>', $now))
            ->when($this->status === 'paused', fn ($query) => $query->where('status', 'paused')->where('ends_at', '>', $now))
            ->when($this->status === 'expired', fn ($query) => $query->where(function ($statusQuery) use ($now): void {
                $statusQuery->where('status', 'expired')->orWhere(function ($dateQuery) use ($now): void {
                    $dateQuery->whereIn('status', ['active', 'paused'])->where('ends_at', '<=', $now);
                });
            }))
            ->when($this->plan !== '', fn ($query) => $query->whereHas('plans', fn ($planQuery) => $planQuery->where('code', $this->plan)))
            ->when($this->eligibility !== '', fn ($query) => $query->where('purchase_eligibility', $this->eligibility))
            ->latest('id')
            ->paginate(15);
        $promotions->getCollection()->each(function (MembershipPromotion $promotion) use ($usage): void {
            $promotion->setAttribute('usage_summary', $usage->forPromotion($promotion));
        });

        return compact('plans', 'promotions');
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-medium text-amber-700 dark:text-amber-300">{{ __('Administration') }}</p>
            <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ __('Membership Promotions') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-zinc-600 dark:text-zinc-300">{{ __('Generate controlled membership codes here, then copy and share them manually. Customer redemption remains unavailable until its separate release gate is completed.') }}</p>
        </div>
    </div>

    <section class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-6">
        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Create promotion draft') }}</h2>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('The code is generated securely. Draft terms can be edited until the first activation.') }}</p>

        <form wire:submit="createPromotion" class="mt-5 space-y-5">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <flux:select wire:model.live="discount_type" :label="__('Discount type')">
                    <flux:select.option value="fixed">{{ __('Fixed QAR amount') }}</flux:select.option>
                    <flux:select.option value="percentage">{{ __('Percentage') }}</flux:select.option>
                </flux:select>
                @if($discount_type === 'fixed')
                    <flux:input wire:model="fixed_amount" inputmode="decimal" placeholder="100.00" :label="__('Discount amount (QAR)')" />
                @else
                    <flux:input wire:model="percentage" inputmode="decimal" placeholder="10.00" :label="__('Discount percentage')" />
                @endif
                <flux:select wire:model="purchase_eligibility" :label="__('Purchase eligibility')">
                    <flux:select.option value="first">{{ __('First purchase only') }}</flux:select.option>
                    <flux:select.option value="renewal">{{ __('Renewals only') }}</flux:select.option>
                    <flux:select.option value="both">{{ __('First purchase and renewals') }}</flux:select.option>
                </flux:select>
                <flux:input wire:model="starts_on" type="date" :label="__('Start date in Qatar')" />
                <flux:input wire:model="ends_on" type="date" :label="__('End date in Qatar')" :description="__('The selected end date is included.')" />
                <flux:input wire:model="total_limit" type="number" min="1" step="1" :label="__('Total redemption limit')" />
                <flux:input wire:model="per_customer_limit" type="number" min="1" step="1" :label="__('Limit per customer')" :description="__('Defaults to one. Offers that can make a plan free are forced to one when activated.')" />
            </div>

            <fieldset>
                <legend class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ __('Eligible membership plans') }}</legend>
                <div class="mt-2 flex flex-wrap gap-3">
                    @foreach($plans as $membershipPlan)
                        <label class="flex min-h-11 items-center gap-3 rounded-lg border border-zinc-200 px-4 py-2 text-sm dark:border-zinc-600">
                            <input wire:model="eligible_plan_ids" type="checkbox" value="{{ $membershipPlan->id }}" class="rounded border-zinc-300 text-amber-600 focus:ring-amber-500">
                            <span><strong>{{ $membershipPlan->meal_count }} {{ __('meals') }}</strong> · QAR {{ number_format($membershipPlan->package_price_cents / 100, 0) }}</span>
                        </label>
                    @endforeach
                </div>
                @error('eligible_plan_ids') <p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
            </fieldset>

            <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-100">
                <strong>{{ __('Eligibility examples') }}</strong>
                <p class="mt-1">{{ __('First purchase means no completed membership. Renewal means any customer with completed membership history, even when older meals remain. Both accepts either case, subject to limits.') }}</p>
                <p class="mt-1">{{ __('A 100% result creates only a pending meal plan request. It never creates credit or meal allowance automatically.') }}</p>
            </div>

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="createPromotion">
                    <span wire:loading.remove wire:target="createPromotion">{{ __('Generate draft code') }}</span>
                    <span wire:loading wire:target="createPromotion">{{ __('Generating…') }}</span>
                </flux:button>
            </div>
        </form>
    </section>

    <section class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <flux:select wire:model.live="status" :label="__('Status')">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                <flux:select.option value="draft">{{ __('Draft') }}</flux:select.option>
                <flux:select.option value="live">{{ __('Live') }}</flux:select.option>
                <flux:select.option value="scheduled">{{ __('Scheduled') }}</flux:select.option>
                <flux:select.option value="paused">{{ __('Paused') }}</flux:select.option>
                <flux:select.option value="expired">{{ __('Expired') }}</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="plan" :label="__('Plan')">
                <flux:select.option value="">{{ __('All plans') }}</flux:select.option>
                <flux:select.option value="20">{{ __('20 meals') }}</flux:select.option>
                <flux:select.option value="26">{{ __('26 meals') }}</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="eligibility" :label="__('Eligibility')">
                <flux:select.option value="">{{ __('All purchase types') }}</flux:select.option>
                <flux:select.option value="first">{{ __('First purchase') }}</flux:select.option>
                <flux:select.option value="renewal">{{ __('Renewal') }}</flux:select.option>
                <flux:select.option value="both">{{ __('Both') }}</flux:select.option>
            </flux:select>
        </div>

        @if($promotions->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-300 bg-white px-6 py-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <p class="font-medium text-zinc-900 dark:text-white">{{ __('No promotion codes match these filters.') }}</p>
            </div>
        @else
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach($promotions as $promotion)
                    @php($usage = $promotion->usage_summary)
                    <article wire:key="promotion-{{ $promotion->id }}" class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <code class="text-lg font-semibold tracking-wider text-zinc-900 dark:text-white">{{ $promotion->code }}</code>
                                <p class="mt-1 text-sm text-zinc-500">{{ $promotion->discount_type === 'fixed' ? 'QAR '.number_format($promotion->fixed_amount_cents / 100, 2) : number_format($promotion->percentage_basis_points / 100, 2).'%' }} · {{ ucfirst($promotion->purchase_eligibility) }}</p>
                            </div>
                            <span class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-100">{{ __($promotion->displayStatus()) }}</span>
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                            <div><dt class="text-zinc-500">{{ __('Plans') }}</dt><dd class="font-medium">{{ $promotion->plans->pluck('code')->join(', ') }}</dd></div>
                            <div><dt class="text-zinc-500">{{ __('Completed') }}</dt><dd class="font-medium">{{ $usage['completed'] }}</dd></div>
                            <div><dt class="text-zinc-500">{{ __('Reserved') }}</dt><dd class="font-medium">{{ $usage['reserved'] }}</dd></div>
                            <div><dt class="text-zinc-500">{{ __('Remaining') }}</dt><dd class="font-medium">{{ $usage['remaining'] }}</dd></div>
                        </dl>
                        <div class="mt-4 flex justify-end">
                            <flux:button :href="route('membership-promotions.show', $promotion)" variant="ghost" wire:navigate>{{ __('Manage') }}</flux:button>
                        </div>
                    </article>
                @endforeach
            </div>
            <div>{{ $promotions->links('pagination::tailwind') }}</div>
        @endif
    </section>
</div>

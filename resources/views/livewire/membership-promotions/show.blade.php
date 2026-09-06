<?php

use App\Models\AccountingAuditLog;
use App\Models\MembershipPlan;
use App\Models\MembershipPromotion;
use App\Models\User;
use App\Services\Promotions\MembershipPromotionService;
use App\Services\Promotions\PromotionAccessService;
use App\Services\Promotions\PromotionConflictException;
use App\Services\Promotions\PromotionUsageProjectionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public MembershipPromotion $promotion;
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
    public int|string $increase_total_limit = 1;
    public int $expected_revision = 1;
    public string $operation_uuid = '';

    public function mount(MembershipPromotion $promotion, PromotionAccessService $access): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $access->assertOwns($actor, $promotion);
        $this->operation_uuid = (string) Str::uuid();
        $this->syncPromotion($promotion->load('plans'));
    }

    public function updateDraft(MembershipPromotionService $service): void
    {
        $actor = $this->actor();
        try {
            $updated = $service->updateDraft($actor, $this->promotion, $this->expected_revision, [
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
        } catch (PromotionConflictException $exception) {
            $this->refreshAfterConflict($exception);

            return;
        }

        $this->finish($updated, __('Promotion draft saved.'));
    }

    public function activate(MembershipPromotionService $service): void
    {
        $this->performStateChange($service, 'activate');
    }

    public function pause(MembershipPromotionService $service): void
    {
        $this->performStateChange($service, 'pause');
    }

    public function resume(MembershipPromotionService $service): void
    {
        $this->performStateChange($service, 'resume');
    }

    public function expire(MembershipPromotionService $service): void
    {
        $this->performStateChange($service, 'expire');
    }

    public function increaseLimit(MembershipPromotionService $service): void
    {
        try {
            $updated = $service->increaseLimit(
                $this->actor(),
                $this->promotion,
                $this->expected_revision,
                (int) $this->increase_total_limit,
                $this->operation_uuid,
            );
        } catch (PromotionConflictException $exception) {
            $this->refreshAfterConflict($exception);

            return;
        }

        $this->finish($updated, __('Promotion total limit increased.'));
    }

    public function copyAsDraft(MembershipPromotionService $service): mixed
    {
        $copy = $service->copyAsDraft($this->actor(), $this->promotion, $this->operation_uuid);
        $this->operation_uuid = (string) Str::uuid();
        session()->flash('status', __('A new promotion draft was created with copied terms and a new code.'));

        return $this->redirectRoute('membership-promotions.show', ['promotion' => $copy->id], navigate: true);
    }

    public function with(PromotionAccessService $access, PromotionUsageProjectionService $usage): array
    {
        $actor = $this->actor();
        $currentPromotion = MembershipPromotion::query()->with('plans')->findOrFail($this->promotion->id);
        $companyId = $access->assertOwns($actor, $currentPromotion);
        $plans = MembershipPlan::query()
            ->where('company_id', $companyId)
            ->whereIn('code', ['20', '26'])
            ->where('is_active', true)
            ->orderBy('meal_count')
            ->get();
        $history = AccountingAuditLog::query()
            ->where('company_id', $companyId)
            ->where('subject_type', MembershipPromotion::class)
            ->where('subject_id', $currentPromotion->id)
            ->latest('id')
            ->limit(50)
            ->get();
        $discount = $currentPromotion->discount_type === 'fixed'
            ? 'QAR '.number_format($currentPromotion->fixed_amount_cents / 100, 2)
            : number_format($currentPromotion->percentage_basis_points / 100, 2).'%';
        $shareText = __('Layla Kitchen membership promotion :code: :discount off the :plans meal plan(s), for :eligibility purchases. Valid in Qatar from :start through :end, subject to the code limits and terms.', [
            'code' => $currentPromotion->code,
            'discount' => $discount,
            'plans' => $currentPromotion->plans->pluck('code')->join(' and '),
            'eligibility' => $currentPromotion->purchase_eligibility,
            'start' => $currentPromotion->starts_at->setTimezone('Asia/Qatar')->toDateString(),
            'end' => $currentPromotion->ends_at->setTimezone('Asia/Qatar')->subDay()->toDateString(),
        ]);

        return [
            'currentPromotion' => $currentPromotion,
            'plans' => $plans,
            'usage' => $usage->forPromotion($currentPromotion),
            'history' => $history,
            'shareText' => $shareText,
        ];
    }

    private function performStateChange(MembershipPromotionService $service, string $action): void
    {
        try {
            $updated = match ($action) {
                'activate' => $service->activate($this->actor(), $this->promotion, $this->expected_revision, $this->operation_uuid),
                'pause' => $service->pause($this->actor(), $this->promotion, $this->expected_revision, $this->operation_uuid),
                'resume' => $service->resume($this->actor(), $this->promotion, $this->expected_revision, $this->operation_uuid),
                'expire' => $service->expire($this->actor(), $this->promotion, $this->expected_revision, $this->operation_uuid),
            };
        } catch (PromotionConflictException $exception) {
            $this->refreshAfterConflict($exception);

            return;
        }

        $message = match ($action) {
            'activate' => __('Promotion activated. Its offer terms are now fixed.'),
            'pause' => __('Promotion paused. Existing protected checkout holds remain valid.'),
            'resume' => __('Promotion resumed.'),
            'expire' => __('Promotion expired. Completed uses remain part of its history.'),
        };
        $this->finish($updated, $message);
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function finish(MembershipPromotion $promotion, string $message): void
    {
        $this->resetErrorBag('promotion');
        $this->operation_uuid = (string) Str::uuid();
        $this->syncPromotion($promotion->load('plans'));
        session()->flash('status', $message);
    }

    private function refreshAfterConflict(PromotionConflictException $exception): void
    {
        $this->syncPromotion(MembershipPromotion::query()->with('plans')->findOrFail($this->promotion->id));
        $this->operation_uuid = (string) Str::uuid();
        $this->addError('promotion', $exception->getMessage());
    }

    private function syncPromotion(MembershipPromotion $promotion): void
    {
        $this->promotion = $promotion;
        $this->discount_type = (string) $promotion->discount_type;
        $this->fixed_amount = $promotion->fixed_amount_cents === null ? '' : number_format($promotion->fixed_amount_cents / 100, 2, '.', '');
        $this->percentage = $promotion->percentage_basis_points === null ? '' : number_format($promotion->percentage_basis_points / 100, 2, '.', '');
        $this->purchase_eligibility = (string) $promotion->purchase_eligibility;
        $this->starts_on = $promotion->starts_at->copy()->setTimezone('Asia/Qatar')->toDateString();
        $this->ends_on = $promotion->ends_at->copy()->setTimezone('Asia/Qatar')->subDay()->toDateString();
        $this->total_limit = (int) $promotion->total_limit;
        $this->per_customer_limit = (int) $promotion->per_customer_limit;
        $this->eligible_plan_ids = $promotion->plans->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->increase_total_limit = (int) $promotion->total_limit + 1;
        $this->expected_revision = (int) $promotion->revision;
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <a href="{{ route('membership-promotions.index') }}" class="text-sm font-medium text-amber-700 hover:underline dark:text-amber-300" wire:navigate>{{ __('← Membership promotions') }}</a>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold tracking-wider text-zinc-900 dark:text-white">{{ $currentPromotion->code }}</h1>
                <span class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-100">{{ __($currentPromotion->displayStatus()) }}</span>
                <span class="text-xs text-zinc-500">{{ __('Revision :revision', ['revision' => $currentPromotion->revision]) }}</span>
            </div>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('Share this code manually only after reviewing and activating its terms.') }}</p>
        </div>
        <div class="flex flex-wrap gap-2" x-data="{ copied: false }">
            <flux:button type="button" variant="ghost" icon="clipboard" x-on:click="navigator.clipboard.writeText(@js($currentPromotion->code)); copied = true; setTimeout(() => copied = false, 1800)">
                <span x-show="!copied">{{ __('Copy code') }}</span>
                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
            </flux:button>
            <flux:button type="button" variant="ghost" x-on:click="navigator.clipboard.writeText(@js($shareText)); copied = true; setTimeout(() => copied = false, 1800)">{{ __('Copy offer text') }}</flux:button>
            <flux:button type="button" variant="ghost" wire:click="copyAsDraft" wire:loading.attr="disabled" wire:target="copyAsDraft">{{ __('Copy as new draft') }}</flux:button>
        </div>
    </div>

    @if(session('status'))
        <p role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">{{ session('status') }}</p>
    @endif
    @error('promotion')
        <p role="alert" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">{{ $message }}</p>
    @enderror

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800"><span class="text-sm text-zinc-500">{{ __('Completed') }}</span><strong class="mt-1 block text-2xl">{{ $usage['completed'] }}</strong><small>{{ $usage['paid_uses'] }} {{ __('paid') }} · {{ $usage['free_request_uses'] }} {{ __('free requests') }}</small></div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800"><span class="text-sm text-zinc-500">{{ __('Reserved') }}</span><strong class="mt-1 block text-2xl">{{ $usage['reserved'] }}</strong><small>{{ __('Protected checkout holds') }}</small></div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800"><span class="text-sm text-zinc-500">{{ __('Remaining') }}</span><strong class="mt-1 block text-2xl">{{ $usage['remaining'] }}</strong><small>{{ __('After completed and held uses') }}</small></div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800"><span class="text-sm text-zinc-500">{{ __('Customer limit') }}</span><strong class="mt-1 block text-2xl">{{ $currentPromotion->per_customer_limit }}</strong><small>{{ __('Combined history after merge') }}</small></div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800"><span class="text-sm text-zinc-500">{{ __('Plans') }}</span><strong class="mt-1 block text-xl">{{ $currentPromotion->plans->pluck('code')->join(' + ') }}</strong><small>{{ __('Delivery remains included') }}</small></div>
    </section>

    @if($currentPromotion->status === 'draft')
        <section class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-6">
            <h2 class="text-lg font-semibold">{{ __('Edit draft offer') }}</h2>
            <p class="mt-1 text-sm text-zinc-500">{{ __('After activation these terms are immutable. Use Copy as new draft for a different offer.') }}</p>
            <form wire:submit="updateDraft" class="mt-5 space-y-5">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <flux:select wire:model.live="discount_type" :label="__('Discount type')">
                        <flux:select.option value="fixed">{{ __('Fixed QAR amount') }}</flux:select.option>
                        <flux:select.option value="percentage">{{ __('Percentage') }}</flux:select.option>
                    </flux:select>
                    @if($discount_type === 'fixed')
                        <flux:input wire:model="fixed_amount" inputmode="decimal" :label="__('Discount amount (QAR)')" />
                    @else
                        <flux:input wire:model="percentage" inputmode="decimal" :label="__('Discount percentage')" />
                    @endif
                    <flux:select wire:model="purchase_eligibility" :label="__('Purchase eligibility')">
                        <flux:select.option value="first">{{ __('First purchase only') }}</flux:select.option>
                        <flux:select.option value="renewal">{{ __('Renewals only') }}</flux:select.option>
                        <flux:select.option value="both">{{ __('First purchase and renewals') }}</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="starts_on" type="date" :label="__('Start date in Qatar')" />
                    <flux:input wire:model="ends_on" type="date" :label="__('End date in Qatar')" :description="__('The selected end date is included.')" />
                    <flux:input wire:model="total_limit" type="number" min="1" step="1" :label="__('Total redemption limit')" />
                    <flux:input wire:model="per_customer_limit" type="number" min="1" step="1" :label="__('Limit per customer')" />
                </div>
                <fieldset>
                    <legend class="text-sm font-medium">{{ __('Eligible membership plans') }}</legend>
                    <div class="mt-2 flex flex-wrap gap-3">
                        @foreach($plans as $membershipPlan)
                            <label class="flex min-h-11 items-center gap-3 rounded-lg border border-zinc-200 px-4 py-2 text-sm dark:border-zinc-600">
                                <input wire:model="eligible_plan_ids" type="checkbox" value="{{ $membershipPlan->id }}" class="rounded border-zinc-300 text-amber-600 focus:ring-amber-500">
                                <span>{{ $membershipPlan->meal_count }} {{ __('meals') }} · QAR {{ number_format($membershipPlan->package_price_cents / 100, 0) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('eligible_plan_ids') <p class="mt-2 text-sm text-red-600" role="alert">{{ $message }}</p> @enderror
                </fieldset>
                <div class="flex flex-wrap justify-end gap-3">
                    <flux:button type="submit" variant="ghost" wire:loading.attr="disabled" wire:target="updateDraft">{{ __('Save draft') }}</flux:button>
                    <flux:button type="button" variant="primary" wire:click="activate" wire:confirm="{{ __('Activate this promotion and freeze its offer terms?') }}" wire:loading.attr="disabled" wire:target="activate">{{ __('Activate promotion') }}</flux:button>
                </div>
            </form>
        </section>
    @else
        <section class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold">{{ __('Fixed offer terms') }}</h2>
                    <dl class="mt-3 grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-zinc-500">{{ __('Discount') }}</dt><dd class="font-medium">{{ $currentPromotion->discount_type === 'fixed' ? 'QAR '.number_format($currentPromotion->fixed_amount_cents / 100, 2) : number_format($currentPromotion->percentage_basis_points / 100, 2).'%' }}</dd></div>
                        <div><dt class="text-zinc-500">{{ __('Eligibility') }}</dt><dd class="font-medium">{{ ucfirst($currentPromotion->purchase_eligibility) }}</dd></div>
                        <div><dt class="text-zinc-500">{{ __('Qatar dates') }}</dt><dd class="font-medium">{{ $starts_on }} {{ __('through') }} {{ $ends_on }}</dd></div>
                        <div><dt class="text-zinc-500">{{ __('Total limit') }}</dt><dd class="font-medium">{{ $currentPromotion->total_limit }}</dd></div>
                    </dl>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if($currentPromotion->status === 'active')
                        <flux:button type="button" variant="ghost" wire:click="pause" wire:loading.attr="disabled" wire:target="pause">{{ __('Pause') }}</flux:button>
                    @elseif($currentPromotion->status === 'paused')
                        <flux:button type="button" variant="primary" wire:click="resume" wire:loading.attr="disabled" wire:target="resume">{{ __('Resume') }}</flux:button>
                    @endif
                    @if($currentPromotion->status !== 'expired')
                        <flux:button type="button" variant="danger" wire:click="expire" wire:confirm="{{ __('Expire this promotion now? Completed uses will remain recorded.') }}" wire:loading.attr="disabled" wire:target="expire">{{ __('Expire now') }}</flux:button>
                    @endif
                </div>
            </div>

            @if($currentPromotion->status !== 'expired')
                <form wire:submit="increaseLimit" class="mt-5 flex flex-col gap-3 border-t border-zinc-200 pt-5 dark:border-zinc-700 sm:flex-row sm:items-end">
                    <div class="w-full sm:max-w-xs"><flux:input wire:model="increase_total_limit" type="number" min="{{ $currentPromotion->total_limit + 1 }}" step="1" :label="__('Increase total limit to')" /></div>
                    <flux:button type="submit" variant="ghost" wire:loading.attr="disabled" wire:target="increaseLimit">{{ __('Increase limit') }}</flux:button>
                </form>
            @endif
        </section>
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
        <div class="border-b border-zinc-200 px-4 py-4 dark:border-zinc-700 sm:px-6"><h2 class="text-lg font-semibold">{{ __('Audit history') }}</h2><p class="mt-1 text-sm text-zinc-500">{{ __('Offer changes are append-only and include the accepted operation and revision.') }}</p></div>
        @if($history->isEmpty())
            <p class="px-4 py-8 text-sm text-zinc-500 sm:px-6">{{ __('No promotion actions recorded.') }}</p>
        @else
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach($history as $event)
                    <div wire:key="promotion-history-{{ $event->id }}" class="flex flex-col gap-1 px-4 py-4 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div><strong>{{ __(str_replace(['membership_promotion.', '_'], ['', ' '], $event->action)) }}</strong><span class="ml-2 text-zinc-500">{{ __('Revision :revision · User #:actor', ['revision' => data_get($event->payload, 'result_revision', '—'), 'actor' => $event->actor_id]) }}</span></div>
                        <time class="text-zinc-500" datetime="{{ $event->created_at?->toISOString() }}">{{ $event->created_at?->setTimezone('Asia/Qatar')->format('M j, Y g:i A') }} {{ __('Qatar') }}</time>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>

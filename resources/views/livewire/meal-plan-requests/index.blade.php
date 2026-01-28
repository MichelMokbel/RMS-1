<?php

use App\Models\MealPlanRequest;
use App\Models\Customer;
use App\Models\MealSubscription;
use App\Models\MealSubscriptionOrder;
use App\Models\Order;
use App\Services\Subscriptions\MealSubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Validation\ValidationException;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $status = 'new';

    public ?int $convertRequestId = null;
    public ?int $convertCustomerId = null;
    public bool $convertAttachOrders = true;
    public bool $convertCreateCustomer = false;
    public bool $convertConfirmCreateCustomer = false;

    public string $convertCustomerName = '';
    public string $convertCustomerPhone = '';
    public ?string $convertCustomerEmail = null;
    public ?string $convertCustomerAddress = null;

    public int $convertBranchId = 1;
    public string $convertStartDate = '';
    public array $convertWeekdays = [1,2,3,4,6,7]; // Mon-Thu, Sat-Sun (no Friday) by default
    public string $convertPreferredRole = 'main';
    public bool $convertIncludeSalad = true;
    public bool $convertIncludeDessert = true;
    public string $convertDefaultOrderType = 'Delivery';
    public ?string $convertDeliveryTime = null;
    public bool $convertGenerateFirstOrder = false;

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function openConvertModal(int $id): void
    {
        $req = MealPlanRequest::find($id);
        if (! $req) {
            return;
        }

        $this->resetErrorBag();

        $this->convertRequestId = $req->id;
        $this->convertCustomerId = null;
        $this->convertAttachOrders = true;
        $this->convertCreateCustomer = false;
        $this->convertConfirmCreateCustomer = false;

        $this->convertCustomerName = (string) $req->customer_name;
        $this->convertCustomerPhone = (string) $req->customer_phone;
        $this->convertCustomerEmail = $req->customer_email ? (string) $req->customer_email : null;
        $this->convertCustomerAddress = $req->delivery_address ? (string) $req->delivery_address : null;

        // Use branch from first linked order if available
        $branchId = 1;
        $startDate = now()->toDateString();
        $orderIds = $req->linkedOrderIds();
        if (! empty($orderIds)) {
            $firstOrder = Order::query()->whereIn('id', $orderIds)->orderBy('scheduled_date')->first();
            if ($firstOrder) {
                $branchId = (int) $firstOrder->branch_id;
                $startDate = $firstOrder->scheduled_date?->format('Y-m-d') ?? $startDate;
            }
        }
        $this->convertBranchId = $branchId;
        $this->convertStartDate = $startDate;

        // Try auto-select customer match by email/phone
        $match = Customer::query()
            ->when($this->convertCustomerEmail, fn ($q) => $q->orWhere('email', $this->convertCustomerEmail))
            ->orWhere('phone', $this->convertCustomerPhone)
            ->orderByDesc('id')
            ->first();
        if ($match) {
            $this->convertCustomerId = (int) $match->id;
        }
    }

    public function acceptNoPlanRequest(int $id): void
    {
        $req = MealPlanRequest::find($id);
        if (! $req) {
            return;
        }

        if ((int) $req->plan_meals > 0) {
            return;
        }

        if (in_array($req->status, ['converted', 'closed'], true)) {
            return;
        }

        $req->status = 'converted';
        $req->save();

        $this->confirmRequestOrders($req);
        session()->flash('status', __('Accepted. Orders confirmed.'));
    }

    private function estimateEndDate(string $startDate, array $weekdays, int $totalMeals): ?string
    {
        if ($totalMeals <= 0) {
            return null;
        }
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        if (empty($weekdays)) {
            return null;
        }

        $d = Carbon::parse($startDate)->startOfDay();
        $count = 0;

        // Safety cap to avoid infinite loops (e.g. invalid weekdays)
        for ($i = 0; $i < 800; $i++) {
            $weekday = (int) $d->format('N'); // 1-7
            if (in_array($weekday, $weekdays, true)) {
                $count++;
                if ($count >= $totalMeals) {
                    return $d->format('Y-m-d');
                }
            }
            $d->addDay();
        }

        return null;
    }

    public function convertToSubscription(MealSubscriptionService $subscriptionService, \App\Services\Orders\OrderTotalsService $totalsService): void
    {
        $reqId = $this->convertRequestId;
        if (! $reqId) {
            return;
        }

        /** @var MealPlanRequest|null $req */
        $req = MealPlanRequest::find($reqId);
        if (! $req) {
            return;
        }
        if ((int) $req->plan_meals <= 0) {
            session()->flash('status', __('This request has no meal plan to convert.'));
            return;
        }

        // Prevent double conversion
        if (MealSubscription::where('meal_plan_request_id', $req->id)->exists()) {
            $req->status = 'converted';
            $req->save();
            $this->confirmRequestOrders($req);
            session()->flash('status', __('Already converted.'));
            return;
        }

        $this->validate([
            'convertBranchId' => ['required', 'integer', 'min:1'],
            'convertStartDate' => ['required', 'date'],
            'convertWeekdays' => ['required', 'array', 'min:1'],
            'convertWeekdays.*' => ['integer', 'min:1', 'max:7'],
            'convertPreferredRole' => ['required', 'in:main,diet,vegetarian'],
            'convertDefaultOrderType' => ['required', 'in:Delivery,Takeaway'],
            'convertDeliveryTime' => ['nullable', 'date_format:H:i'],
            'convertCustomerId' => ['nullable', 'integer', 'min:1'],
            'convertCreateCustomer' => ['boolean'],
            'convertConfirmCreateCustomer' => ['boolean'],
            'convertCustomerName' => ['required', 'string', 'max:255'],
            'convertCustomerPhone' => ['required', 'string', 'max:50'],
            'convertCustomerEmail' => ['nullable', 'string', 'max:255'],
            'convertCustomerAddress' => ['nullable', 'string'],
        ]);

        $actorId = Auth::id();
        if (! $actorId) {
            throw ValidationException::withMessages(['auth' => __('Authentication required.')]);
        }

        try {
            DB::transaction(function () use ($req, $subscriptionService, $actorId) {
                $customer = null;
                if ($this->convertCustomerId) {
                    $customer = Customer::find($this->convertCustomerId);
                }

                if (! $customer) {
                    if (! $this->convertCreateCustomer || ! $this->convertConfirmCreateCustomer) {
                        throw ValidationException::withMessages([
                            'convertCustomerId' => __('Select an existing customer, or enable “Create new customer” and confirm.'),
                        ]);
                    }

                    $customer = Customer::create([
                        'customer_code' => null,
                        'name' => $this->convertCustomerName,
                        'customer_type' => Customer::TYPE_SUBSCRIPTION,
                        'contact_name' => null,
                        'phone' => $this->convertCustomerPhone,
                        'email' => $this->convertCustomerEmail,
                        'billing_address' => null,
                        'delivery_address' => $this->convertCustomerAddress,
                        'country' => 'Qatar',
                        'default_payment_method_id' => null,
                        'credit_limit' => 0,
                        'credit_terms_days' => 0,
                        'credit_status' => null,
                        'is_active' => 1,
                        'notes' => null,
                        'created_by' => $actorId,
                        'updated_by' => null,
                    ]);
                }

                $totalMeals = (int) $req->plan_meals;
                $weekdays = array_values(array_unique(array_map('intval', $this->convertWeekdays)));
                $estimatedEnd = $this->estimateEndDate($this->convertStartDate, $weekdays, $totalMeals);

                $sub = $subscriptionService->save([
                    'customer_id' => $customer->id,
                    'branch_id' => $this->convertBranchId,
                    'status' => 'active',
                    'start_date' => $this->convertStartDate,
                    'end_date' => $estimatedEnd,
                    'default_order_type' => $this->convertDefaultOrderType,
                    'delivery_time' => $this->convertDeliveryTime,
                    'address_snapshot' => $this->convertCustomerAddress,
                    'phone_snapshot' => $this->convertCustomerPhone,
                    'preferred_role' => $this->convertPreferredRole,
                    'include_salad' => $this->convertIncludeSalad,
                    'include_dessert' => $this->convertIncludeDessert,
                    'notes' => $req->notes,
                    'weekdays' => $weekdays,
                ], null, $actorId);

                // Link to request + quota
                $sub->plan_meals_total = $totalMeals;
                $sub->meals_used = (int) ($sub->meals_used ?? 0);
                $sub->meal_plan_request_id = $req->id;
                $sub->save();

                if ($this->convertAttachOrders) {
                    $orderIds = $req->linkedOrderIds();
                    $orders = Order::query()->whereIn('id', $orderIds)->get();

                    $used = 0;
                    $maxDate = null;
                    foreach ($orders as $o) {
                        // Reclassify website orders as subscription for ops filtering
                        $o->customer_id = $customer->id;
                        $o->source = 'Subscription';
                        $o->save();

                        $serviceDate = $o->scheduled_date?->format('Y-m-d');
                        if (! $serviceDate) {
                            continue;
                        }

                        $exists = MealSubscriptionOrder::query()
                            ->where('subscription_id', $sub->id)
                            ->where('branch_id', $o->branch_id)
                            ->whereDate('service_date', $serviceDate)
                            ->exists();

                        if (! $exists) {
                            MealSubscriptionOrder::create([
                                'subscription_id' => $sub->id,
                                'order_id' => $o->id,
                                'service_date' => $serviceDate,
                                'branch_id' => $o->branch_id,
                            ]);
                            $used++;
                        }

                        $totalsService->recalc($o->fresh(['items']));

                        $maxDate = $maxDate ? max($maxDate, $serviceDate) : $serviceDate;
                    }

                    if ($used > 0) {
                        $sub->meals_used = (int) ($sub->meals_used ?? 0) + $used;
                        if ($sub->plan_meals_total !== null && (int) $sub->meals_used >= (int) $sub->plan_meals_total) {
                            $sub->status = 'expired';
                            // If already consumed immediately, end at the last attached order date (best effort)
                            $sub->end_date = $maxDate;
                        }
                        $sub->save();
                    }
                }

                // Optional immediate generation for start date (uses the published menu for that date)
                if ($this->convertGenerateFirstOrder) {
                    app(\App\Services\Orders\SubscriptionOrderGenerationService::class)
                        ->generateForDate($this->convertStartDate, $this->convertBranchId, $actorId, dryRun: false);
                }

                $req->status = 'converted';
                $req->save();
                $this->confirmRequestOrders($req);
            });

            session()->flash('status', __('Converted to subscription.'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            $this->addError('convertCustomerId', __('Conversion failed. Please review the fields and try again.'));
        }
    }

    public function markStatus(int $id, string $status): void
    {
        if (! in_array($status, ['new','contacted','converted','closed'], true)) {
            return;
        }
        $req = MealPlanRequest::find($id);
        if (! $req) {
            return;
        }
        $req->status = $status;
        $req->save();
        session()->flash('status', __('Updated.'));
    }

    private function confirmRequestOrders(MealPlanRequest $req): void
    {
        $orderIds = $req->linkedOrderIds();
        $orderIds = array_values(array_filter($orderIds, fn ($id) => $id !== null && $id !== ''));
        if (empty($orderIds)) {
            return;
        }

        Order::query()
            ->whereIn('id', $orderIds)
            ->where('status', 'Draft')
            ->update(['status' => 'Confirmed']);
    }

    public function with(): array
    {
        $query = MealPlanRequest::query()->orderByDesc('created_at');
        if (Schema::hasTable('meal_plan_request_orders')) {
            $query->withCount('orders');
        }
        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        $requests = $query->paginate(25);
        $requestIds = collect($requests->items())->pluck('id')->all();
        $subscriptionsByRequestId = empty($requestIds)
            ? []
            : MealSubscription::query()
                ->whereIn('meal_plan_request_id', $requestIds)
                ->get()
                ->keyBy('meal_plan_request_id')
                ->all();

        return [
            'requests' => $requests,
            'subscriptionsByRequestId' => $subscriptionsByRequestId,
        ];
    }
}; ?>

<div>
<div class="w-full max-w-6xl mx-auto px-4 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Meal Plan Requests') }}</h1>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Status') }}</label>
                <select wire:model.live="status" class="rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="new">{{ __('New') }}</option>
                    <option value="contacted">{{ __('Contacted') }}</option>
                    <option value="converted">{{ __('Converted') }}</option>
                    <option value="closed">{{ __('Closed') }}</option>
                    <option value="all">{{ __('All') }}</option>
                </select>
            </div>
        </div>
    </div>

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="overflow-x-auto">
            <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
                <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Plan') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Phone') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Email') }}</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Orders') }}</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    @forelse($requests as $r)
                        <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $r->customer_name }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">
                                {{ $r->plan_meals > 0 ? $r->plan_meals : __('No plan') }}
                            </td>
                            <td class="px-3 py-2 text-sm">
                                @php
                                    $statusLabel = match ($r->status) {
                                        'new' => __('New'),
                                        'contacted' => __('Contacted'),
                                        'converted' => __('Converted'),
                                        'closed' => __('Closed'),
                                        default => (string) $r->status,
                                    };
                                    if ((int) $r->plan_meals <= 0 && $r->status === 'converted') {
                                        $statusLabel = __('Accepted');
                                    }
                                    $statusClasses = match ($r->status) {
                                        'new' => 'bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100',
                                        'contacted' => 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-100',
                                        'converted' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-100',
                                        'closed' => 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-100',
                                        default => 'bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-100',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusClasses }}">
                                    {{ $statusLabel }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $r->customer_phone }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $r->customer_email ?? '—' }}</td>
                            <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $r->orders_count ?? (is_array($r->order_ids) ? count($r->order_ids) : 0) }}</td>
                            <td class="px-3 py-2 text-sm text-right">
                                <div class="flex justify-end gap-2">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        :href="route('meal-plan-requests.show', $r)"
                                        wire:navigate
                                    >{{ __('View') }}</flux:button>

                                    @php
                                        $sub = $subscriptionsByRequestId[$r->id] ?? null;
                                        $hasPlan = (int) $r->plan_meals > 0;
                                    @endphp
                                    @if($sub)
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            :href="route('subscriptions.show', $sub)"
                                            wire:navigate
                                        >{{ __('Subscription') }}</flux:button>
                                    @endif

                                    <flux:button
                                        size="sm"
                                        type="button"
                                        :variant="in_array($r->status, ['contacted','converted','closed'], true) ? 'primary' : 'ghost'"
                                        :color="in_array($r->status, ['contacted','converted','closed'], true) ? 'blue' : null"
                                        :disabled="in_array($r->status, ['contacted','converted','closed'], true)"
                                        wire:click="markStatus({{ $r->id }}, 'contacted')"
                                    >{{ __('Contacted') }}</flux:button>
                                    @if ($hasPlan)
                                        <flux:button
                                            size="sm"
                                            type="button"
                                            :variant="in_array($r->status, ['converted','closed'], true) ? 'primary' : 'ghost'"
                                            :color="in_array($r->status, ['converted','closed'], true) ? 'emerald' : null"
                                            :disabled="in_array($r->status, ['converted','closed'], true)"
                                            x-data=""
                                            x-on:click.prevent="$wire.openConvertModal({{ $r->id }}); $dispatch('modal-show', { name: 'convert-mpr' })"
                                        >{{ __('Converted') }}</flux:button>
                                    @else
                                        <flux:button
                                            size="sm"
                                            type="button"
                                            :variant="in_array($r->status, ['converted','closed'], true) ? 'primary' : 'ghost'"
                                            :color="in_array($r->status, ['converted','closed'], true) ? 'emerald' : null"
                                            :disabled="in_array($r->status, ['converted','closed'], true)"
                                            wire:click="acceptNoPlanRequest({{ $r->id }})"
                                        >{{ __('Accept') }}</flux:button>
                                    @endif
                                    <flux:button
                                        size="sm"
                                        type="button"
                                        :variant="$r->status === 'closed' ? 'danger' : 'ghost'"
                                        :disabled="$r->status === 'closed'"
                                        wire:click="markStatus({{ $r->id }}, 'closed')"
                                    >{{ __('Close') }}</flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-4 text-center text-sm text-neutral-600 dark:text-neutral-300">
                                {{ __('No requests.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pt-2">
            {{ $requests->links() }}
        </div>
    </div>
</div>

<flux:modal name="convert-mpr" focusable class="max-w-3xl">
    <form wire:submit="convertToSubscription" class="space-y-6">
        <div class="space-y-1">
            <flux:heading size="lg">{{ __('Convert to Subscription') }}</flux:heading>
            <flux:subheading>{{ __('Link or create a customer, then create a subscription with a 20/26 meal quota.') }}</flux:subheading>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <flux:input wire:model="convertCustomerName" :label="__('Customer Name')" />
            <flux:input wire:model="convertCustomerPhone" :label="__('Phone')" />
            <flux:input wire:model="convertCustomerEmail" :label="__('Email')" />
            <flux:input wire:model="convertCustomerAddress" :label="__('Delivery Address')" />
        </div>

        <div class="rounded-md border border-neutral-200 p-4 dark:border-neutral-700 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <div class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Customer Match') }}</div>
                <div class="flex items-center gap-3">
                    <label class="inline-flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                        <input type="checkbox" class="rounded border-neutral-300 dark:border-neutral-700" wire:model="convertCreateCustomer" />
                        {{ __('Create new customer') }}
                    </label>
                </div>
            </div>

            @php
                $matches = collect();

                $email = $convertCustomerEmail ? trim((string) $convertCustomerEmail) : '';
                $phone = $convertCustomerPhone ? trim((string) $convertCustomerPhone) : '';

                if ($email !== '' || $phone !== '') {
                    $q = \App\Models\Customer::query();
                    if ($email !== '' && $phone !== '') {
                        $q->where(function ($qq) use ($email, $phone) {
                            $qq->where('email', $email)->orWhere('phone', $phone);
                        });
                    } elseif ($email !== '') {
                        $q->where('email', $email);
                    } else {
                        $q->where('phone', $phone);
                    }
                    $matches = $q->limit(5)->get();
                }
            @endphp

            @if($matches->isNotEmpty())
                <div class="text-xs text-neutral-600 dark:text-neutral-300">{{ __('Possible matches:') }}</div>
                <div class="space-y-2">
                    @foreach($matches as $c)
                        <label class="flex items-center justify-between gap-3 rounded-md border border-neutral-200 px-3 py-2 text-sm dark:border-neutral-700">
                            <div>
                                <div class="font-semibold text-neutral-900 dark:text-neutral-100">{{ $c->name }}</div>
                                <div class="text-xs text-neutral-600 dark:text-neutral-300">{{ $c->phone }} · {{ $c->email ?? '—' }} · #{{ $c->id }}</div>
                            </div>
                            <input type="radio" name="convertCustomerPick" value="{{ $c->id }}" wire:model="convertCustomerId" />
                        </label>
                    @endforeach
                </div>
            @else
                <div class="text-sm text-neutral-700 dark:text-neutral-200">{{ __('No matches found by phone/email.') }}</div>
            @endif

            @if($convertCreateCustomer)
                <label class="inline-flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                    <input type="checkbox" class="rounded border-neutral-300 dark:border-neutral-700" wire:model="convertConfirmCreateCustomer" />
                    {{ __('I confirm creating a new customer if none is selected.') }}
                </label>
            @endif
        </div>

        <div class="rounded-md border border-neutral-200 p-4 dark:border-neutral-700 space-y-4">
            <div class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Subscription Settings') }}</div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <flux:input wire:model="convertBranchId" type="number" min="1" :label="__('Branch')" />
                <flux:input wire:model="convertStartDate" type="date" :label="__('Start date')" />
                <flux:input wire:model="convertDeliveryTime" type="time" :label="__('Delivery time (optional)')" />
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Preferred role') }}</label>
                    <select wire:model="convertPreferredRole" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="main">{{ __('Main') }}</option>
                        <option value="diet">{{ __('Diet') }}</option>
                        <option value="vegetarian">{{ __('Vegetarian') }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Order type') }}</label>
                    <select wire:model="convertDefaultOrderType" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                        <option value="Delivery">{{ __('Delivery') }}</option>
                        <option value="Takeaway">{{ __('Takeaway') }}</option>
                    </select>
                </div>
                <div class="flex items-center gap-4 pt-6">
                    <flux:checkbox wire:model="convertIncludeSalad" :label="__('Include salad')" />
                    <flux:checkbox wire:model="convertIncludeDessert" :label="__('Include dessert')" />
                </div>
            </div>

            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Weekdays') }}</label>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $wd => $label)
                        <label class="inline-flex items-center gap-2 rounded-full border border-neutral-200 px-3 py-1 text-sm dark:border-neutral-700">
                            <input type="checkbox" value="{{ $wd }}" wire:model="convertWeekdays" />
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="pt-2">
                <flux:checkbox wire:model="convertAttachOrders" :label="__('Attach existing website order(s) to this subscription and count them as used meals')" />
            </div>
            <div>
                <flux:checkbox wire:model="convertGenerateFirstOrder" :label="__('Generate subscription order for the start date now (requires published menu)')" />
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>
            <flux:button variant="primary" type="submit">{{ __('Convert') }}</flux:button>
        </div>
    </form>
</flux:modal>

 </div>

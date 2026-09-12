<?php

use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\OrderSheet;
use App\Services\OrderSheet\OrderSheetEditorService;
use App\Services\OrderSheet\OrderSheetExcelExport;
use App\Services\OrderSheet\OrderSheetPublishService;
use App\Services\OrderSheet\OrderSheetLocationService;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public array $initialPayload = [];
    public string $today = '';

    public function mount(OrderSheetEditorService $editor): void
    {
        $this->today = now()->toDateString();
        $this->initialPayload = $editor->snapshot($this->today);
    }

    public function canCreateCustomer(): bool
    {
        $user = auth()->user();

        return $user?->isActive()
            && ($user->hasAnyRole(['admin', 'manager']) || $user->can('receivables.access'));
    }

    #[Renderless]
    public function loadDate(string $date, OrderSheetEditorService $editor): array
    {
        $this->authorizeEditor();
        $this->validateDate($date);

        return $editor->snapshot($date);
    }

    #[Renderless]
    public function searchCustomers(
        string $term,
        string $date,
        OrderSheetLocationService $locations,
        OrderSheetEditorService $editor,
    ): array
    {
        $this->authorizeEditor();
        $this->validateDate($date);
        $term = trim($term);
        if (mb_strlen($term) < 1) {
            return [];
        }

        $customers = Customer::query()
            ->active()
            ->search($term)
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name', 'phone', 'delivery_address']);
        $customerLocations = $locations->forCustomers($customers);
        $subscriptionBenefits = $editor->subscriptionBenefits($date, $customers->modelKeys());

        return $customers
            ->map(function (Customer $customer) use ($customerLocations, $subscriptionBenefits) {
                $benefit = $subscriptionBenefits->get($customer->id);

                return [
                    'id' => (int) $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'location' => $customerLocations->get($customer->id, ''),
                    'has_subscription' => $benefit !== null,
                    'subscription_appetizer' => $benefit['appetizer'] ?? null,
                ];
            })->all();
    }

    #[Renderless]
    public function searchDishes(string $term): array
    {
        $this->authorizeEditor();
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }

        return MenuItem::query()
            ->search($term)
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name'])
            ->map(fn (MenuItem $item) => ['id' => (int) $item->id, 'name' => $item->name])
            ->all();
    }

    #[Renderless]
    public function createCustomer(string $name, string $phone): array
    {
        abort_unless($this->canCreateCustomer(), 403);
        $data = Validator::make(compact('name', 'phone'), [
            'name' => ['required', 'string', 'max:255'],
            'phone' => array_filter([
                'required', 'string', 'max:50',
                config('customers.enforce_unique_phone') ? 'unique:customers,phone' : null,
            ]),
        ])->validate();
        $customer = Customer::create([
            'name' => trim($data['name']),
            'phone' => trim($data['phone']),
            'customer_type' => Customer::TYPE_RETAIL,
            'is_active' => true,
            'credit_limit' => 0,
            'credit_terms_days' => 0,
            'created_by' => auth()->id(),
        ]);

        return [
            'id' => (int) $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'location' => $customer->delivery_address ?? '',
            'has_subscription' => false,
            'subscription_appetizer' => null,
        ];
    }

    #[Renderless]
    public function saveSheet(string $date, array $rows, array $removedOrderIds, OrderSheetEditorService $editor): array
    {
        $this->authorizeEditor();
        $this->validateEditorPayload($date, $rows, $removedOrderIds);
        $editor->save($date, $rows, $removedOrderIds);

        return [
            'payload' => $editor->snapshot($date),
            'message' => __('Sheet saved at :time.', ['time' => now()->format('H:i:s')]),
        ];
    }

    #[Renderless]
    public function publishSheet(
        string $date,
        array $rows,
        array $removedOrderIds,
        OrderSheetEditorService $editor,
        OrderSheetPublishService $publisher,
    ): array {
        $this->authorizeEditor();
        $this->validateEditorPayload($date, $rows, $removedOrderIds);
        $editor->save($date, $rows, $removedOrderIds);
        $sheet = OrderSheet::with(['entries.quantities.dailyDishMenuItem', 'entries.extras'])
            ->where('sheet_date', $date)->firstOrFail();
        $result = $publisher->publish($sheet, auth()->id());
        $parts = [];
        if ($result['created'] > 0) {
            $parts[] = trans_choice(':count order created|:count orders created', $result['created'], ['count' => $result['created']]);
        }
        if ($result['updated'] > 0) {
            $parts[] = trans_choice(':count order updated|:count orders updated', $result['updated'], ['count' => $result['updated']]);
        }

        return [
            'payload' => $editor->snapshot($date),
            'message' => $parts ? implode(', ', $parts).'.' : __('All entries are up to date.'),
        ];
    }

    public function exportSheet(string $date, array $rows, OrderSheetEditorService $editor): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $this->authorizeEditor();
        $this->validateEditorPayload($date, $rows, []);
        $payload = $editor->snapshot($date);

        return app(OrderSheetExcelExport::class)->download(
            $date,
            $payload['menuItems'],
            collect($rows)->map(fn ($row) => [
                'order_id' => $row['order_id'] ?? null,
                'customer_name' => $row['customer_name'] ?? '',
                'location' => $row['location'] ?? '',
                'qty' => $row['quantities'] ?? [],
                'extras' => collect($row['extras'] ?? [])->map(fn ($extra) => [
                    'menu_item_id' => $extra['menu_item_id'] ?? null,
                    'menu_item_name' => $extra['name'] ?? '',
                    'quantity' => $extra['quantity'] ?? 0,
                ])->all(),
                'remarks' => $row['remarks'] ?? '',
            ])->all(),
        );
    }

    private function authorizeEditor(): void
    {
        abort_unless(
            auth()->user()?->isActive()
                && auth()->user()->hasAnyRole(['admin', 'manager', 'staff', 'cashier']),
            403,
        );
    }

    private function validateDate(string $date): void
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
    }

    private function validateEditorPayload(string $date, array $rows, array $removedOrderIds): void
    {
        Validator::make(compact('date', 'rows', 'removedOrderIds'), [
            'date' => ['required', 'date_format:Y-m-d'],
            'rows' => ['array', 'max:500'],
            'rows.*.key' => ['required', 'string', 'max:100'],
            'rows.*.order_id' => ['nullable', 'integer'],
            'rows.*.customer_id' => ['nullable', 'integer'],
            'rows.*.customer_name' => ['nullable', 'string', 'max:255'],
            'rows.*.location' => ['nullable', 'string', 'max:1000'],
            'rows.*.remarks' => ['nullable', 'string', 'max:2000'],
            'rows.*.quantities' => ['array'],
            'rows.*.quantities.*' => ['integer', 'min:0', 'max:10000'],
            'rows.*.extras' => ['array', 'max:100'],
            'rows.*.extras.*.menu_item_id' => ['required', 'integer'],
            'rows.*.extras.*.name' => ['required', 'string', 'max:255'],
            'rows.*.extras.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'removedOrderIds' => ['array', 'max:500'],
            'removedOrderIds.*' => ['integer'],
        ])->validate();
    }
};
?>

<div
    wire:ignore
    data-order-sheet-v2
    class="flex min-h-0 flex-col gap-4 p-4 sm:p-6"
    style="height: calc(100dvh - 4rem)"
    x-data="orderSheetV2(@js($initialPayload), @js($today), @js($this->canCreateCustomer()))"
>
    <section class="z-30 shrink-0 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" aria-labelledby="order-sheet-title">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <div class="min-w-0">
                <div class="flex items-center gap-3">
                    <h1 id="order-sheet-title" class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('Order Sheet') }}</h1>
                    <span x-show="dirty" class="rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{{ __('Unsaved') }}</span>
                </div>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Plan customer dishes, save the sheet, then publish the orders.') }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex min-h-11 items-center overflow-hidden rounded-lg border border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-800">
                    <button type="button" x-on:click="shiftDate(-1)" x-bind:disabled="loading || saving" class="min-h-11 min-w-11 px-3 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:opacity-50 dark:text-zinc-200 dark:hover:bg-zinc-700" aria-label="{{ __('Previous day') }}">{{ __('Previous') }}</button>
                    <label class="sr-only" for="order-sheet-date">{{ __('Order sheet date') }}</label>
                    <input id="order-sheet-date" type="date" x-model="date" x-on:change="changeDate($event.target.value)" x-bind:disabled="loading || saving" class="min-h-11 border-x border-zinc-300 bg-transparent px-3 text-sm font-medium text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 dark:border-zinc-600 dark:text-white" />
                    <button type="button" x-on:click="shiftDate(1)" x-bind:disabled="loading || saving" class="min-h-11 min-w-11 px-3 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:opacity-50 dark:text-zinc-200 dark:hover:bg-zinc-700" aria-label="{{ __('Next day') }}">{{ __('Next') }}</button>
                </div>
                <button type="button" x-show="date !== today" x-on:click="changeDate(today)" x-bind:disabled="loading || saving" class="min-h-11 rounded-lg border border-zinc-300 bg-white px-3 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">{{ __('Today') }}</button>
            </div>
        </div>

        <div class="mt-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="rounded-lg bg-zinc-100 px-3 py-2 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"><strong x-text="filledRows"></strong> {{ __('customers') }}</span>
                <span class="rounded-lg bg-zinc-100 px-3 py-2 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"><strong x-text="totalItems"></strong> {{ __('items') }}</span>
                <span x-show="loading" role="status" class="rounded-lg bg-blue-50 px-3 py-2 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200">{{ __('Loading date…') }}</span>
                <span x-show="message" x-text="message" role="status" aria-live="polite" class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200"></span>
                <span x-show="error" x-text="error" role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-red-800 dark:bg-red-900/40 dark:text-red-200"></span>
            </div>

            <div class="flex flex-wrap gap-2">
                <button x-show="canCreateCustomer" type="button" x-on:click="openCustomerCreator()" class="min-h-11 rounded-lg border border-zinc-300 bg-white px-3 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">{{ __('New customer') }}</button>
                <button type="button" x-on:click="exportExcel()" x-bind:disabled="busy" class="min-h-11 rounded-lg border border-zinc-300 bg-white px-3 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">{{ __('Excel') }}</button>
                <button type="button" x-on:click="printSheet()" class="min-h-11 rounded-lg border border-zinc-300 bg-white px-3 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">{{ __('Print') }}</button>
                <a x-bind:href="`{{ route('order-sheet.print.by-order') }}?date=${date}`" target="_blank" class="inline-flex min-h-11 items-center rounded-lg border border-zinc-300 bg-white px-3 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">{{ __('By order') }}</a>
                <a x-bind:href="`{{ route('order-sheet.print.by-item') }}?date=${date}`" target="_blank" class="inline-flex min-h-11 items-center rounded-lg border border-zinc-300 bg-white px-3 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">{{ __('Item totals') }}</a>
                <button type="button" x-on:click="save()" x-bind:disabled="busy" class="min-h-11 rounded-lg bg-zinc-900 px-4 text-sm font-semibold text-white hover:bg-zinc-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:opacity-50 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"><span x-text="saving ? '{{ __('Saving…') }}' : '{{ __('Save') }}'"></span></button>
                <button type="button" x-on:click="publish()" x-bind:disabled="busy" class="min-h-11 rounded-lg bg-emerald-600 px-4 text-sm font-semibold text-white hover:bg-emerald-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:opacity-50"><span x-text="publishing ? '{{ __('Publishing…') }}' : '{{ __('Publish orders') }}'"></span></button>
            </div>
        </div>
    </section>

    <section class="relative min-h-0 flex-1 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900" aria-label="{{ __('Order sheet editor') }}">
        <div x-show="loading" class="absolute inset-0 z-20 flex items-center justify-center bg-white/80 dark:bg-zinc-900/80" role="status">
            <span class="rounded-lg bg-white px-4 py-3 text-sm font-medium text-zinc-700 shadow dark:bg-zinc-800 dark:text-zinc-200">{{ __('Loading order sheet…') }}</span>
        </div>

        <div class="h-full overflow-auto overscroll-contain" data-sheet-scroll>
            <table class="min-w-full border-separate border-spacing-0 text-sm" x-bind:style="`min-width:${1104 + menuItems.length * 112}px`">
                <thead class="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th scope="col" class="sticky left-0 z-20 w-64 min-w-64 border-b border-r border-zinc-200 bg-zinc-50 px-3 py-3 text-left font-semibold text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ __('Customer') }}</th>
                        <th scope="col" class="w-48 min-w-48 border-b border-r border-zinc-200 px-3 py-3 text-left font-semibold text-zinc-700 dark:border-zinc-700 dark:text-zinc-200">{{ __('Location') }}</th>
                        <template x-for="item in menuItems" :key="item.id">
                            <th scope="col" class="w-28 min-w-28 border-b border-r border-zinc-200 px-2 py-3 text-center font-semibold text-zinc-700 dark:border-zinc-700 dark:text-zinc-200" x-text="item.name"></th>
                        </template>
                        <th scope="col" class="w-64 min-w-64 border-b border-r border-zinc-200 px-3 py-3 text-left font-semibold text-zinc-700 dark:border-zinc-700 dark:text-zinc-200">{{ __('Other dishes') }}</th>
                        <th scope="col" class="w-56 min-w-56 border-b border-r border-zinc-200 px-3 py-3 text-left font-semibold text-zinc-700 dark:border-zinc-700 dark:text-zinc-200">{{ __('Remarks') }}</th>
                        <th scope="col" class="w-20 min-w-20 border-b border-r border-zinc-200 px-2 py-3 text-center font-semibold text-zinc-700 dark:border-zinc-700 dark:text-zinc-200">{{ __('Total') }}</th>
                        <th scope="col" class="w-24 min-w-24 border-b border-zinc-200 px-2 py-3 text-center font-semibold text-zinc-700 dark:border-zinc-700 dark:text-zinc-200">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, rowIndex) in rows" :key="row.key">
                        <tr class="group odd:bg-white even:bg-zinc-50/50 dark:odd:bg-zinc-900 dark:even:bg-zinc-800/30">
                            <td class="sticky left-0 z-[5] border-b border-r border-zinc-200 bg-inherit p-2 align-top dark:border-zinc-700">
                                <label class="sr-only" x-bind:for="`customer-${row.key}`">{{ __('Customer') }}</label>
                                <input x-bind:id="`customer-${row.key}`" type="text" x-model="row.customer_name" x-on:focus="openCustomerSearch(row, $el)" x-on:input="customerInput(row, $el)" x-on:keydown="customerKeydown($event)" autocomplete="off" placeholder="{{ __('Search customer') }}" class="min-h-10 w-full rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                                <div class="mt-1 flex min-h-5 items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    <span x-show="row.order_id" x-text="row.order_id ? `Order #${row.order_id}` : ''"></span>
                                    <button x-show="row.customer_id" type="button" x-on:click="clearCustomer(row)" class="font-medium text-zinc-600 underline hover:text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 dark:text-zinc-300 dark:hover:text-white">{{ __('Clear') }}</button>
                                </div>
                            </td>
                            <td class="border-b border-r border-zinc-200 p-2 align-top dark:border-zinc-700">
                                <label class="sr-only" x-bind:for="`location-${row.key}`">{{ __('Location') }}</label>
                                <input x-bind:id="`location-${row.key}`" type="text" x-model="row.location" x-on:input="markDirty(); ensureTrailingBlank()" placeholder="{{ __('Delivery location') }}" class="min-h-10 w-full rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                            </td>
                            <template x-for="item in menuItems" :key="`${row.key}-${item.id}`">
                                <td class="border-b border-r border-zinc-200 p-2 align-top dark:border-zinc-700">
                                    <div class="flex items-center justify-center gap-1">
                                        <button type="button" x-on:click="adjustQuantity(row, item.id, -1)" x-bind:disabled="quantity(row, item.id) === 0" class="inline-flex size-10 items-center justify-center rounded-lg border border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 disabled:opacity-30 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200" x-bind:aria-label="`{{ __('Decrease') }} ${item.name}`"><flux:icon.minus class="size-4" /></button>
                                        <label class="sr-only" x-bind:for="`quantity-${row.key}-${item.id}`" x-text="`${item.name} {{ __('quantity') }}`"></label>
                                        <input x-bind:id="`quantity-${row.key}-${item.id}`" type="number" min="0" step="1" x-bind:value="quantity(row, item.id)" x-on:change="setQuantity(row, item.id, $event.target.value)" class="h-10 w-12 rounded-lg border border-zinc-300 bg-white px-1 text-center font-semibold tabular-nums text-zinc-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                                        <button type="button" x-on:click="adjustQuantity(row, item.id, 1)" class="inline-flex size-10 items-center justify-center rounded-lg bg-zinc-900 text-white hover:bg-zinc-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 dark:bg-white dark:text-zinc-900" x-bind:aria-label="`{{ __('Increase') }} ${item.name}`"><flux:icon.plus class="size-4" /></button>
                                    </div>
                                </td>
                            </template>
                            <td class="border-b border-r border-zinc-200 p-2 align-top dark:border-zinc-700">
                                <div class="flex flex-wrap gap-1.5">
                                    <template x-for="(extra, extraIndex) in row.extras" :key="`${row.key}-${extra.menu_item_id}`">
                                        <div class="inline-flex min-h-10 items-center gap-1 rounded-lg bg-amber-50 px-2 text-xs text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">
                                            <span class="max-w-28 truncate font-medium" x-text="extra.name"></span>
                                            <span x-show="isSubscriptionAppetizer(row, extra)" class="rounded bg-emerald-100 px-1.5 py-1 font-semibold text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200">{{ __('Subscription') }}</span>
                                            <button x-show="!isSubscriptionAppetizer(row, extra)" type="button" x-on:click="adjustExtra(row, extraIndex, -1)" class="inline-flex size-8 items-center justify-center rounded-md hover:bg-amber-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 dark:hover:bg-amber-900" x-bind:aria-label="`{{ __('Decrease') }} ${extra.name}`"><flux:icon.minus class="size-3" /></button>
                                            <span class="min-w-5 text-center font-semibold tabular-nums" x-text="extra.quantity"></span>
                                            <button x-show="!isSubscriptionAppetizer(row, extra)" type="button" x-on:click="adjustExtra(row, extraIndex, 1)" class="inline-flex size-8 items-center justify-center rounded-md hover:bg-amber-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 dark:hover:bg-amber-900" x-bind:aria-label="`{{ __('Increase') }} ${extra.name}`"><flux:icon.plus class="size-3" /></button>
                                            <button x-show="!isSubscriptionAppetizer(row, extra)" type="button" x-on:click="removeExtra(row, extraIndex)" class="inline-flex size-8 items-center justify-center rounded-md text-red-700 hover:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 dark:text-red-300 dark:hover:bg-red-900/30" x-bind:aria-label="`{{ __('Remove') }} ${extra.name}`"><flux:icon.x-mark class="size-3" /></button>
                                        </div>
                                    </template>
                                    <button type="button" x-on:click="openDishPicker(row)" class="min-h-10 rounded-lg border border-dashed border-zinc-300 bg-white px-3 text-xs font-medium text-zinc-700 hover:border-zinc-500 hover:bg-zinc-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">{{ __('Add dish') }}</button>
                                </div>
                            </td>
                            <td class="border-b border-r border-zinc-200 p-2 align-top dark:border-zinc-700">
                                <label class="sr-only" x-bind:for="`remarks-${row.key}`">{{ __('Remarks') }}</label>
                                <input x-bind:id="`remarks-${row.key}`" type="text" x-model="row.remarks" x-on:input="markDirty(); ensureTrailingBlank()" placeholder="{{ __('Notes') }}" class="min-h-10 w-full rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                            </td>
                            <td class="border-b border-r border-zinc-200 px-2 py-3 text-center align-top font-semibold tabular-nums text-zinc-900 dark:border-zinc-700 dark:text-white" x-text="rowTotal(row)"></td>
                            <td class="border-b border-zinc-200 p-2 text-center align-top dark:border-zinc-700">
                                <button type="button" x-on:click="removeRow(rowIndex)" x-bind:disabled="!rowHasContent(row)" class="min-h-10 rounded-lg px-3 text-xs font-medium text-red-700 hover:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 disabled:cursor-default disabled:opacity-30 dark:text-red-300 dark:hover:bg-red-900/30">{{ __('Delete') }}</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot class="sticky bottom-0 z-10 bg-zinc-100 font-semibold text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100">
                    <tr>
                        <th scope="row" class="sticky left-0 z-20 border-r border-t border-zinc-300 bg-zinc-100 px-3 py-3 text-left dark:border-zinc-600 dark:bg-zinc-800">{{ __('Totals') }}</th>
                        <td class="border-r border-t border-zinc-300 dark:border-zinc-600"></td>
                        <template x-for="item in menuItems" :key="`total-${item.id}`">
                            <td class="border-r border-t border-zinc-300 px-2 py-3 text-center tabular-nums dark:border-zinc-600" x-text="dishTotal(item.id)"></td>
                        </template>
                        <td class="border-r border-t border-zinc-300 px-3 py-3 dark:border-zinc-600" x-text="extraSummary"></td>
                        <td class="border-r border-t border-zinc-300 dark:border-zinc-600"></td>
                        <td class="border-r border-t border-zinc-300 px-2 py-3 text-center tabular-nums dark:border-zinc-600" x-text="totalItems"></td>
                        <td class="border-t border-zinc-300 dark:border-zinc-600"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div x-show="rows.length === 1 && !rowHasContent(rows[0]) && menuItems.length === 0" class="pointer-events-none absolute inset-x-0 top-20 z-[6] flex justify-center px-4">
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 shadow-sm dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-100">{{ __('There is no daily menu for this date. You can still add customers and other dishes.') }}</div>
        </div>
    </section>

    <div class="shrink-0">
        <button type="button" x-on:click="addBlankRow(true)" class="min-h-11 rounded-lg border border-zinc-300 bg-white px-4 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">{{ __('Add row') }}</button>
    </div>

    <div x-show="customerSearch.open" x-cloak x-bind:style="`top:${customerSearch.top}px;left:${customerSearch.left}px;width:${customerSearch.width}px`" x-on:pointerdown.outside="closeCustomerSearch()" class="fixed z-50 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-900" role="listbox" aria-label="{{ __('Customer results') }}">
        <div x-show="customerSearch.loading" class="px-3 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Searching…') }}</div>
        <template x-for="(customer, index) in customerSearch.results" :key="customer.id">
            <button type="button" x-on:pointerdown.prevent="selectCustomer(customer)" x-on:mouseenter="customerSearch.activeIndex = index" x-bind:class="index === customerSearch.activeIndex ? 'bg-zinc-100 dark:bg-zinc-800' : ''" class="block w-full px-3 py-2 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600" role="option" x-bind:aria-selected="index === customerSearch.activeIndex">
                <span class="flex items-center gap-2 text-sm font-medium text-zinc-900 dark:text-white">
                    <span x-text="customer.name"></span>
                    <span x-show="customer.has_subscription" class="rounded bg-emerald-100 px-1.5 py-0.5 text-[0.6875rem] font-semibold text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200">{{ __('Subscription') }}</span>
                </span>
                <span class="block text-xs text-zinc-500 dark:text-zinc-400" x-text="customer.phone || '{{ __('No phone') }}'"></span>
            </button>
        </template>
        <button x-show="canCreateCustomer && customerSearch.term.trim()" type="button" x-on:pointerdown.prevent="openCustomerCreator(customerSearch.rowKey, customerSearch.term)" class="block min-h-11 w-full border-t border-zinc-200 px-3 py-2 text-left text-sm font-semibold text-blue-700 hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 dark:border-zinc-700 dark:text-blue-300 dark:hover:bg-blue-900/20">{{ __('Create new customer') }} “<span x-text="customerSearch.term"></span>”</button>
        <div x-show="!customerSearch.loading && customerSearch.term && customerSearch.results.length === 0" class="px-3 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No matching customers') }}</div>
    </div>

    <dialog x-ref="dishDialog" x-on:close="closeDishPicker()" class="w-[min(32rem,calc(100%-2rem))] rounded-xl border border-zinc-200 bg-white p-0 shadow-2xl backdrop:bg-zinc-950/50 dark:border-zinc-700 dark:bg-zinc-900">
        <form method="dialog" class="border-b border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Add another dish') }}</h2>
                <button class="min-h-10 rounded-lg px-3 text-sm font-medium text-zinc-600 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 dark:text-zinc-300 dark:hover:bg-zinc-800">{{ __('Close') }}</button>
            </div>
        </form>
        <div class="p-4">
            <label for="dish-search" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ __('Search dishes') }}</label>
            <input id="dish-search" x-ref="dishSearchInput" type="search" x-model="dishSearch.term" x-on:input="searchDishes()" x-on:keydown="dishKeydown($event)" class="min-h-11 w-full rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" autocomplete="off" />
            <div class="mt-3 max-h-80 overflow-auto" role="listbox" aria-label="{{ __('Dish results') }}">
                <div x-show="dishSearch.loading" class="px-3 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Searching…') }}</div>
                <template x-for="(dish, index) in dishSearch.results" :key="dish.id">
                    <button type="button" x-on:click="selectDish(dish)" x-on:mouseenter="dishSearch.activeIndex = index" x-bind:class="index === dishSearch.activeIndex ? 'bg-zinc-100 dark:bg-zinc-800' : ''" class="block min-h-11 w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-zinc-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 dark:text-white" role="option" x-bind:aria-selected="index === dishSearch.activeIndex" x-text="dish.name"></button>
                </template>
                <p x-show="!dishSearch.loading && dishSearch.term.length >= 2 && dishSearch.results.length === 0" class="px-3 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No matching dishes') }}</p>
                <p x-show="dishSearch.term.length < 2" class="px-3 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Enter at least two characters') }}</p>
            </div>
        </div>
    </dialog>

    <dialog x-ref="customerDialog" x-on:close="resetCustomerCreator()" class="w-[min(30rem,calc(100%-2rem))] rounded-xl border border-zinc-200 bg-white p-0 shadow-2xl backdrop:bg-zinc-950/50 dark:border-zinc-700 dark:bg-zinc-900">
        <form x-on:submit.prevent="createCustomer()">
            <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Create customer') }}</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Add a name and phone number, then continue entering the order.') }}</p>
            </div>
            <div class="space-y-4 p-4">
                <div>
                    <label for="new-customer-name" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ __('Name') }}</label>
                    <input id="new-customer-name" x-ref="newCustomerName" type="text" x-model="customerCreator.name" required maxlength="255" class="min-h-11 w-full rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                </div>
                <div>
                    <label for="new-customer-phone" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ __('Phone number') }}</label>
                    <input id="new-customer-phone" type="tel" x-model="customerCreator.phone" required maxlength="50" class="min-h-11 w-full rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" />
                </div>
                <p x-show="customerCreator.error" x-text="customerCreator.error" class="text-sm text-red-700 dark:text-red-300" role="alert"></p>
            </div>
            <div class="flex justify-end gap-2 border-t border-zinc-200 p-4 dark:border-zinc-700">
                <button type="button" x-on:click="$refs.customerDialog.close()" class="min-h-11 rounded-lg border border-zinc-300 px-4 text-sm font-medium text-zinc-700 hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800">{{ __('Cancel') }}</button>
                <button type="submit" x-bind:disabled="customerCreator.saving" class="min-h-11 rounded-lg bg-zinc-900 px-4 text-sm font-semibold text-white hover:bg-zinc-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 disabled:opacity-50 dark:bg-white dark:text-zinc-900"><span x-text="customerCreator.saving ? '{{ __('Creating…') }}' : '{{ __('Create and add') }}'"></span></button>
            </div>
        </form>
    </dialog>

    <style>
        [x-cloak] { display: none !important; }

        @media (max-width: 767px) {
            [data-order-sheet-v2] { height: auto !important; min-height: calc(100dvh - 4rem); }
            [data-order-sheet-v2] > section:nth-of-type(2) { min-height: 65dvh; }
        }
    </style>
</div>

@script
<script>
    Alpine.data('orderSheetV2', (initialPayload, today, canCreateCustomer) => ({
        today,
        canCreateCustomer,
        date: initialPayload.date,
        menuItems: [],
        rows: [],
        removedOrderIds: [],
        loading: false,
        saving: false,
        publishing: false,
        exporting: false,
        dirty: false,
        message: '',
        error: '',
        loadRequest: 0,
        customerRequest: 0,
        dishRequest: 0,
        customerTimer: null,
        dishTimer: null,
        customerSearch: { open: false, rowKey: null, term: '', results: [], loading: false, activeIndex: 0, top: 0, left: 0, width: 320 },
        dishSearch: { rowKey: null, term: '', results: [], loading: false, activeIndex: 0 },
        customerCreator: { rowKey: null, name: '', phone: '', saving: false, error: '' },

        init() {
            this.applyPayload(initialPayload);
        },

        get busy() {
            return this.loading || this.saving || this.publishing || this.exporting;
        },

        get filledRows() {
            return this.rows.filter((row) => String(row.customer_name || '').trim()).length;
        },

        get totalItems() {
            return this.rows.reduce((total, row) => total + this.rowTotal(row), 0);
        },

        get extraSummary() {
            const total = this.rows.reduce((sum, row) => sum + row.extras.reduce((extraSum, extra) => extraSum + Number(extra.quantity || 0), 0), 0);
            return total ? `${total} {{ __('items') }}` : '—';
        },

        applyPayload(payload) {
            this.date = payload.date;
            this.menuItems = payload.menuItems || [];
            this.rows = (payload.rows || []).map((row) => this.normalizeRow(row));
            this.removedOrderIds = [];
            this.ensureTrailingBlank();
            this.dirty = false;
            this.closeCustomerSearch();
        },

        normalizeRow(row) {
            const quantities = {};
            this.menuItems.forEach((item) => quantities[item.id] = Math.max(0, Number(row.quantities?.[item.id] || 0)));
            return {
                key: row.key || this.newKey(),
                order_id: row.order_id || null,
                customer_id: row.customer_id || null,
                customer_name: row.customer_name || '',
                location: row.location || '',
                has_subscription: Boolean(row.has_subscription),
                subscription_appetizer: row.subscription_appetizer || null,
                quantities,
                extras: (row.extras || []).map((extra) => ({ menu_item_id: Number(extra.menu_item_id), name: extra.name || '', quantity: Math.max(1, Number(extra.quantity || 1)) })),
                remarks: row.remarks || '',
            };
        },

        newKey() {
            return `blank-${window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`}`;
        },

        blankRow() {
            const quantities = {};
            this.menuItems.forEach((item) => quantities[item.id] = 0);
            return { key: this.newKey(), order_id: null, customer_id: null, customer_name: '', location: '', has_subscription: false, subscription_appetizer: null, quantities, extras: [], remarks: '' };
        },

        rowHasContent(row) {
            return Boolean(String(row.customer_name || '').trim() || String(row.location || '').trim() || String(row.remarks || '').trim() || this.rowTotal(row));
        },

        ensureTrailingBlank() {
            if (!this.rows.length || this.rowHasContent(this.rows[this.rows.length - 1])) this.rows.push(this.blankRow());
        },

        addBlankRow(focus = false) {
            const row = this.blankRow();
            this.rows.push(row);
            if (focus) this.$nextTick(() => document.getElementById(`customer-${row.key}`)?.focus());
        },

        markDirty() {
            this.dirty = true;
            this.message = '';
            this.error = '';
        },

        quantity(row, itemId) {
            return Math.max(0, Number(row.quantities?.[itemId] || 0));
        },

        setQuantity(row, itemId, value) {
            row.quantities[itemId] = Math.max(0, Math.trunc(Number(value) || 0));
            this.syncSubscriptionAppetizer(row);
            this.markDirty();
            this.ensureTrailingBlank();
        },

        adjustQuantity(row, itemId, delta) {
            row.quantities[itemId] = Math.max(0, this.quantity(row, itemId) + delta);
            this.syncSubscriptionAppetizer(row);
            this.markDirty();
            this.ensureTrailingBlank();
        },

        rowTotal(row) {
            return Object.values(row.quantities || {}).reduce((sum, quantity) => sum + Number(quantity || 0), 0)
                + row.extras.reduce((sum, extra) => sum + Number(extra.quantity || 0), 0);
        },

        dishTotal(itemId) {
            return this.rows.reduce((sum, row) => sum + this.quantity(row, itemId), 0);
        },

        mainQuantity(row) {
            return this.menuItems
                .filter((item) => item.role === 'main')
                .reduce((sum, item) => sum + this.quantity(row, item.id), 0);
        },

        isSubscriptionAppetizer(row, extra) {
            return Boolean(row.subscription_appetizer)
                && Number(extra.menu_item_id) === Number(row.subscription_appetizer.menu_item_id);
        },

        clearSubscriptionAppetizer(row) {
            if (row.subscription_appetizer) {
                row.extras = row.extras.filter((extra) => !this.isSubscriptionAppetizer(row, extra));
            }
            row.has_subscription = false;
            row.subscription_appetizer = null;
        },

        syncSubscriptionAppetizer(row) {
            if (!row.subscription_appetizer) return;
            const quantity = this.mainQuantity(row);
            row.extras = row.extras.filter((extra) => !this.isSubscriptionAppetizer(row, extra));
            if (quantity > 0) {
                row.extras.push({
                    menu_item_id: Number(row.subscription_appetizer.menu_item_id),
                    name: row.subscription_appetizer.name,
                    quantity,
                });
            }
        },

        removeRow(index) {
            const row = this.rows[index];
            if (!row || !this.rowHasContent(row)) return;
            if (row.order_id) this.removedOrderIds.push(Number(row.order_id));
            this.rows.splice(index, 1);
            this.ensureTrailingBlank();
            this.markDirty();
        },

        clearCustomer(row) {
            this.clearSubscriptionAppetizer(row);
            row.customer_id = null;
            row.customer_name = '';
            this.markDirty();
            this.$nextTick(() => document.getElementById(`customer-${row.key}`)?.focus());
        },

        openCustomerSearch(row, input) {
            const rect = input.getBoundingClientRect();
            this.customerSearch.open = true;
            this.customerSearch.rowKey = row.key;
            this.customerSearch.term = row.customer_name;
            this.customerSearch.left = Math.max(8, Math.min(rect.left, window.innerWidth - Math.min(384, window.innerWidth - 16)));
            this.customerSearch.top = Math.min(rect.bottom + 4, window.innerHeight - 260);
            this.customerSearch.width = Math.max(280, Math.min(rect.width, 384, window.innerWidth - 16));
            this.searchCustomers();
        },

        customerInput(row, input) {
            this.clearSubscriptionAppetizer(row);
            row.customer_id = null;
            this.markDirty();
            this.ensureTrailingBlank();
            this.openCustomerSearch(row, input);
        },

        searchCustomers() {
            clearTimeout(this.customerTimer);
            const term = this.customerSearch.term = String(this.rows.find((row) => row.key === this.customerSearch.rowKey)?.customer_name || '').trim();
            if (!term) {
                this.customerSearch.results = [];
                this.customerSearch.loading = false;
                return;
            }
            const request = ++this.customerRequest;
            this.customerSearch.loading = true;
            this.customerTimer = setTimeout(async () => {
                try {
                    const results = await this.$wire.searchCustomers(term, this.date);
                    if (request === this.customerRequest) {
                        this.customerSearch.results = results;
                        this.customerSearch.activeIndex = 0;
                    }
                } catch (error) {
                    this.showError(error);
                } finally {
                    if (request === this.customerRequest) this.customerSearch.loading = false;
                }
            }, 180);
        },

        customerKeydown(event) {
            if (!this.customerSearch.open) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeCustomerSearch();
            } else if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.customerSearch.activeIndex = Math.min(this.customerSearch.results.length - 1, this.customerSearch.activeIndex + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.customerSearch.activeIndex = Math.max(0, this.customerSearch.activeIndex - 1);
            } else if (event.key === 'Enter' && this.customerSearch.results[this.customerSearch.activeIndex]) {
                event.preventDefault();
                this.selectCustomer(this.customerSearch.results[this.customerSearch.activeIndex]);
            }
        },

        selectCustomer(customer) {
            const row = this.rows.find((candidate) => candidate.key === this.customerSearch.rowKey);
            if (!row) return;
            this.clearSubscriptionAppetizer(row);
            row.customer_id = customer.id;
            row.customer_name = customer.name;
            row.has_subscription = Boolean(customer.has_subscription);
            row.subscription_appetizer = customer.subscription_appetizer || null;
            if (!String(row.location || '').trim()) row.location = customer.location || '';
            this.syncSubscriptionAppetizer(row);
            this.closeCustomerSearch();
            this.markDirty();
            if (row.has_subscription && !row.subscription_appetizer) {
                this.error = '{{ __('The default subscription appetizer is not configured.') }}';
            }
            this.ensureTrailingBlank();
        },

        closeCustomerSearch() {
            clearTimeout(this.customerTimer);
            this.customerRequest++;
            this.customerSearch.open = false;
            this.customerSearch.rowKey = null;
            this.customerSearch.results = [];
            this.customerSearch.loading = false;
        },

        openDishPicker(row) {
            this.dishSearch = { rowKey: row.key, term: '', results: [], loading: false, activeIndex: 0 };
            this.$refs.dishDialog.showModal();
            this.$nextTick(() => this.$refs.dishSearchInput.focus());
        },

        closeDishPicker() {
            clearTimeout(this.dishTimer);
            this.dishRequest++;
            this.dishSearch = { rowKey: null, term: '', results: [], loading: false, activeIndex: 0 };
        },

        searchDishes() {
            clearTimeout(this.dishTimer);
            const term = this.dishSearch.term.trim();
            if (term.length < 2) {
                this.dishSearch.results = [];
                return;
            }
            const request = ++this.dishRequest;
            this.dishSearch.loading = true;
            this.dishTimer = setTimeout(async () => {
                try {
                    const results = await this.$wire.searchDishes(term);
                    if (request === this.dishRequest) {
                        this.dishSearch.results = results;
                        this.dishSearch.activeIndex = 0;
                    }
                } catch (error) {
                    this.showError(error);
                } finally {
                    if (request === this.dishRequest) this.dishSearch.loading = false;
                }
            }, 180);
        },

        dishKeydown(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.$refs.dishDialog.close();
            } else if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.dishSearch.activeIndex = Math.min(this.dishSearch.results.length - 1, this.dishSearch.activeIndex + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.dishSearch.activeIndex = Math.max(0, this.dishSearch.activeIndex - 1);
            } else if (event.key === 'Enter' && this.dishSearch.results[this.dishSearch.activeIndex]) {
                event.preventDefault();
                this.selectDish(this.dishSearch.results[this.dishSearch.activeIndex]);
            }
        },

        selectDish(dish) {
            const row = this.rows.find((candidate) => candidate.key === this.dishSearch.rowKey);
            if (!row) return;
            const existing = row.extras.find((extra) => Number(extra.menu_item_id) === Number(dish.id));
            if (existing) existing.quantity += 1;
            else row.extras.push({ menu_item_id: Number(dish.id), name: dish.name, quantity: 1 });
            this.syncSubscriptionAppetizer(row);
            this.markDirty();
            this.ensureTrailingBlank();
            this.$refs.dishDialog.close();
        },

        adjustExtra(row, index, delta) {
            const extra = row.extras[index];
            if (!extra) return;
            extra.quantity = Math.max(1, Number(extra.quantity || 1) + delta);
            this.markDirty();
        },

        removeExtra(row, index) {
            row.extras.splice(index, 1);
            this.markDirty();
        },

        openCustomerCreator(rowKey = null, name = '') {
            this.closeCustomerSearch();
            const fallback = this.rows.find((row) => !this.rowHasContent(row)) || this.rows[this.rows.length - 1];
            this.customerCreator = { rowKey: rowKey || fallback?.key, name: name || '', phone: '', saving: false, error: '' };
            this.$refs.customerDialog.showModal();
            this.$nextTick(() => this.$refs.newCustomerName.focus());
        },

        resetCustomerCreator() {
            this.customerCreator = { rowKey: null, name: '', phone: '', saving: false, error: '' };
        },

        async createCustomer() {
            if (this.customerCreator.saving) return;
            this.customerCreator.saving = true;
            this.customerCreator.error = '';
            try {
                const customer = await this.$wire.createCustomer(this.customerCreator.name, this.customerCreator.phone);
                const row = this.rows.find((candidate) => candidate.key === this.customerCreator.rowKey);
                if (row) {
                    row.customer_id = customer.id;
                    row.customer_name = customer.name;
                    if (!row.location) row.location = customer.location || '';
                }
                this.markDirty();
                this.ensureTrailingBlank();
                this.$refs.customerDialog.close();
                this.message = '{{ __('Customer created and added.') }}';
            } catch (error) {
                this.customerCreator.error = this.errorMessage(error);
            } finally {
                this.customerCreator.saving = false;
            }
        },

        formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        },

        shiftDate(days) {
            const next = new Date(`${this.date}T12:00:00`);
            next.setDate(next.getDate() + days);
            this.changeDate(this.formatDate(next));
        },

        async changeDate(nextDate) {
            if (!nextDate || nextDate === this.date || this.loading) return;
            const previousDate = this.date;
            const request = ++this.loadRequest;
            this.date = nextDate;
            this.loading = true;
            this.message = '';
            this.error = '';
            try {
                const payload = await this.$wire.loadDate(nextDate);
                if (request === this.loadRequest) this.applyPayload(payload);
            } catch (error) {
                if (request === this.loadRequest) {
                    this.date = previousDate;
                    this.showError(error);
                }
            } finally {
                if (request === this.loadRequest) this.loading = false;
            }
        },

        payloadRows() {
            return JSON.parse(JSON.stringify(this.rows));
        },

        async save() {
            if (this.busy) return;
            this.saving = true;
            this.message = '';
            this.error = '';
            try {
                const result = await this.$wire.saveSheet(this.date, this.payloadRows(), [...new Set(this.removedOrderIds)]);
                this.applyPayload(result.payload);
                this.message = result.message;
            } catch (error) {
                this.showError(error);
            } finally {
                this.saving = false;
            }
        },

        async publish() {
            if (this.busy) return;
            this.publishing = true;
            this.message = '';
            this.error = '';
            try {
                const result = await this.$wire.publishSheet(this.date, this.payloadRows(), [...new Set(this.removedOrderIds)]);
                this.applyPayload(result.payload);
                this.message = result.message;
            } catch (error) {
                this.showError(error);
            } finally {
                this.publishing = false;
            }
        },

        printSheet() {
            const win = window.open('', '_blank');
            if (!win) {
                this.error = '{{ __('Allow pop-ups to print the order sheet.') }}';
                return;
            }

            const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
            })[character]);
            const prettyDate = this.date
                ? new Date(`${this.date}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' })
                : '';
            const headings = ['Customer', 'Location', ...this.menuItems.map((item) => item.name), 'Other dishes', 'Total', 'Remarks'];
            const printableRows = this.rows.filter((row) => String(row.customer_name || '').trim());
            const body = printableRows.map((row) => {
                const extras = (row.extras || []).filter((extra) => Number(extra.quantity) > 0);
                const quantityCells = this.menuItems
                    .map((item) => `<td>${this.quantity(row, item.id) || '—'}</td>`)
                    .join('');
                const extraNames = extras
                    .map((extra) => `${escapeHtml(extra.name)} ×${Number(extra.quantity)}`)
                    .join(', ');

                return `<tr><td>${escapeHtml(row.customer_name)}</td><td>${escapeHtml(row.location)}</td>${quantityCells}<td>${extraNames || '—'}</td><td>${this.rowTotal(row) || '—'}</td><td>${escapeHtml(row.remarks)}</td></tr>`;
            }).join('');
            const dishTotals = this.menuItems.map((item) => this.dishTotal(item.id));
            const extraTotals = new Map();
            printableRows.flatMap((row) => row.extras || []).forEach((extra) => {
                if (Number(extra.quantity) > 0) {
                    extraTotals.set(extra.name, Number(extraTotals.get(extra.name) || 0) + Number(extra.quantity));
                }
            });
            const extraTotal = [...extraTotals.values()].reduce((sum, quantity) => sum + quantity, 0);
            const extraSummary = [...extraTotals]
                .map(([name, quantity]) => `${escapeHtml(name)} ×${quantity}`)
                .join(', ');
            const grandTotal = dishTotals.reduce((sum, quantity) => sum + quantity, 0) + extraTotal;
            const totals = `<tr><th>Total</th><td></td>${dishTotals.map((total) => `<td>${total || '—'}</td>`).join('')}<td>${extraSummary || '—'}</td><td>${grandTotal || '—'}</td><td></td></tr>`;
            const headingHtml = headings.map((heading) => `<th>${escapeHtml(heading)}</th>`).join('');
            const printTable = `<table><thead><tr>${headingHtml}</tr></thead><tbody>${body}</tbody><tfoot>${totals}</tfoot></table>`;
            const blankRows = Array.from({ length: 14 }, () => `<tr>${'<td>&nbsp;</td>'.repeat(headings.length)}</tr>`).join('');
            const blankTable = `<table><thead><tr>${headingHtml}</tr></thead><tbody>${blankRows}</tbody></table>`;
            const extraPages = Array.from({ length: 2 }, (_, index) =>
                `<section class="blank-sheet"><h2>Additional orders — ${prettyDate} (${index + 1}/2)</h2>${blankTable}</section>`
            ).join('');

            win.document.write(`<!doctype html><html><head><meta charset="utf-8">
            <title>Layla Kitchen — ${prettyDate}</title>
            <style>
                @page { size: A4 landscape; margin: 14mm; }
                * { box-sizing: border-box; }
                body { font-family: 'Times New Roman', Times, serif; color: #18181b; margin: 0; padding: 20px; font-size: 16px; }
                h1 { font-size: 28px; margin: 0 0 2px; }
                .sub { font-size: 13px; color: #71717a; text-transform: uppercase; letter-spacing: 0.18em; }
                .meta { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid #18181b; padding-bottom: 10px; margin-bottom: 16px; }
                table { width: 100%; border-collapse: collapse; font-size: 16px; }
                th, td { border: 1px solid #d4d4d8; padding: 6px 10px; vertical-align: middle; }
                thead th { background: #f4f4f5; font-size: 14px; text-transform: uppercase; letter-spacing: 0.06em; font-family: 'Times New Roman', Times, serif; }
                .blank-sheet { break-before: page; page-break-before: always; break-inside: avoid; }
                .blank-sheet table { table-layout: fixed; font-size: 11px; }
                .blank-sheet th { min-width: 0; width: auto; height: 30mm; padding: 2px; overflow-wrap: anywhere; }
                .blank-sheet td { height: 7mm; padding: 0 3px; }
                .blank-sheet h2 { font-size: 16px; margin: 0 0 8px; }
            </style></head><body>
            <div class="meta">
                <div><div class="sub">Layla Kitchen — Daily Order Sheet</div><h1>${prettyDate}</h1></div>
                <div style="text-align:right"><div class="sub">Generated ${new Date().toLocaleString('en-GB')}</div></div>
            </div>
            ${printTable}
            ${extraPages}
            <script>setTimeout(()=>window.print(),300);<\/script>
            </body></html>`);
            win.document.close();
        },

        async exportExcel() {
            if (this.busy) return;
            this.exporting = true;
            this.error = '';
            try {
                await this.$wire.exportSheet(this.date, this.payloadRows());
            } catch (error) {
                this.showError(error);
            } finally {
                this.exporting = false;
            }
        },

        errorMessage(error) {
            return error?.message || '{{ __('Something went wrong. Please try again.') }}';
        },

        showError(error) {
            this.error = this.errorMessage(error);
        },
    }));
</script>
@endscript

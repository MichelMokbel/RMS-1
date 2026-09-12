{{-- resources/views/livewire/order-sheet.blade.php --}}
<?php
use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderSheet;
use App\Models\OrderSheetEntry;
use App\Models\OrderSheetEntryExtra;
use App\Services\OrderSheet\OrderSheetPublishService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $sheetDate = '';
    public array $menuItems = [];
    public array $rows = [];

    public string $mobileView = 'card';   // 'card' | 'grid'
    public bool $mobileLayout = false;
    public ?string $compactDrawerRow = null;

    public ?int $activeSearchRow = null;
    public string $customerSearchTerm = '';
    public ?int $newCustomerRow = null;
    public bool $showCustomerForm = false;
    public string $newCustomerName = '';
    public string $newCustomerPhone = '';
    public string $saveStatus = '';

    public function canCreateCustomer(): bool
    {
        $user = auth()->user();
        return $user?->isActive() && ($user->hasAnyRole(['admin', 'manager']) || $user->can('receivables.access'));
    }

    public function startCustomerCreation(?int $rowIndex = null): void
    {
        abort_unless($this->canCreateCustomer(), 403);
        $this->resetValidation();
        if ($rowIndex === null) {
            $rowIndex = $this->activeSearchRow;
        }
        if ($rowIndex === null) {
            $blankRow = collect($this->rows)
                ->search(fn ($row) => blank($row['customer_name']) && blank($row['order_id']));
            if ($blankRow === false) {
                $this->addRow();
                $blankRow = array_key_last($this->rows);
            }
            $rowIndex = $blankRow;
        }
        abort_unless(isset($this->rows[$rowIndex]), 422);
        if (filled($this->rows[$rowIndex]['customer_id'])) {
            $this->addRow();
            $rowIndex = array_key_last($this->rows);
        }
        $this->newCustomerRow = $rowIndex;
        $this->showCustomerForm = true;
        $this->newCustomerName = $this->rows[$rowIndex]['customer_search'];
        $this->newCustomerPhone = '';
        $this->activeSearchRow = null;
    }

    public function createCustomer(): void
    {
        abort_unless($this->canCreateCustomer(), 403);
        abort_unless($this->newCustomerRow !== null && isset($this->rows[$this->newCustomerRow]), 422);
        $this->newCustomerName = trim($this->newCustomerName);
        $this->newCustomerPhone = trim($this->newCustomerPhone);
        $this->validate([
            'newCustomerName' => ['required', 'string', 'max:255'],
            'newCustomerPhone' => array_filter(['required', 'string', 'max:50',
                config('customers.enforce_unique_phone') ? 'unique:customers,phone' : null]),
        ]);
        $customer = Customer::create([
            'name' => $this->newCustomerName, 'phone' => $this->newCustomerPhone,
            'customer_type' => Customer::TYPE_RETAIL, 'is_active' => true,
            'credit_limit' => 0, 'credit_terms_days' => 0, 'created_by' => auth()->id(),
        ]);
        $this->selectCustomer($customer->id, $this->newCustomerRow);
        $this->newCustomerRow = null;
        $this->showCustomerForm = false;
        $this->saveStatus = __('Customer created and added. Enter their dishes, then save the sheet.');
    }

    public function updatedRows(mixed $value, string $key): void
    {
        $this->saveStatus = '';
        if (preg_match('/^(\d+)\.customer_search$/', $key, $matches)) {
            $this->activeSearchRow = (int) $matches[1];
            $this->customerSearchTerm = (string) $value;
            unset($this->customerResults);
        }
    }


    public function mount(): void
    {
        $this->mobileLayout = (bool) preg_match('/Android|iPhone|iPad|iPod|Mobile/i', request()->userAgent() ?? '');
        $this->sheetDate = now()->toDateString();
        $this->loadMenuItems();
        $this->loadRows();
    }

    private function loadMenuItems(): void
    {
        $menu = DailyDishMenu::with(['items.menuItem'])
            ->whereDate('service_date', $this->sheetDate)
            ->first();

        $rolePriority = ['main' => 0, 'diet' => 1, 'vegetarian' => 2, 'salad' => 3, 'dessert' => 4];
        $this->menuItems = $menu
            ? $menu->items
                ->sortBy(fn ($item) => $rolePriority[$item->role] ?? 5)
                ->map(fn ($item) => [
                    'id'           => $item->id,
                    'menu_item_id' => $item->menu_item_id,
                    'name'         => $item->menuItem?->name ?? '—',
                    'role'         => $item->role ?? '',
                ])->values()->toArray()
            : [];
    }

    private function blankRow(): array
    {
        return [
            'row_key'         => 'blank-'.(string) \Illuminate\Support\Str::uuid(),
            'db_id'           => null,
            'order_id'        => null,
            'customer_id'     => null,
            'customer_name'   => '',
            'customer_search' => '',
            'location'        => '',
            'qty'             => collect($this->menuItems)->mapWithKeys(fn ($item) => [$item['id'] => 0])->all(),
            'extras'          => [],
            'remarks'         => '',
        ];
    }

    private function loadRows(): void
    {
        $sheet = OrderSheet::with([
            'entries' => fn ($query) => $query->withContent(),
            'entries.quantities',
            'entries.extras',
            'entries.order',
        ])->whereDate('sheet_date', $this->sheetDate)->first();

        // Lookup: menu_item_id → daily_dish_menu_item_id (for mapping order items → qty columns)
        $menuItemToColumnId = collect($this->menuItems)->keyBy('menu_item_id')->map(fn ($m) => $m['id']);
        $emptyQty = collect($this->menuItems)->mapWithKeys(fn ($item) => [$item['id'] => 0])->all();

        $locations = app(\App\Services\OrderSheet\OrderSheetLocationService::class)
            ->forOrders(new \Illuminate\Database\Eloquent\Collection($sheet?->entries->pluck('order')->filter()->all() ?? []));

        if ($sheet && $sheet->entries->isNotEmpty()) {
            // Sheet has been saved before — load persisted entries
            $this->rows = $sheet->entries->map(function (OrderSheetEntry $entry) use ($emptyQty, $locations) {
                $qty = collect($this->menuItems)
                    ->mapWithKeys(fn ($item) =>
                        [$item['id'] => (int) optional($entry->quantities->firstWhere('daily_dish_menu_item_id', $item['id']))->quantity ?? 0]
                    )->all();

                $extras = $entry->extras->map(fn ($e) => [
                    'menu_item_id'   => $e->menu_item_id,
                    'menu_item_name' => $e->menu_item_name,
                    'quantity'       => $e->quantity,
                ])->toArray();

                return [
                    'row_key'         => 'entry-'.$entry->id,
                    'db_id'           => $entry->id,
                    'order_id'        => $entry->order_id,
                    'customer_id'     => $entry->customer_id,
                    'customer_name'   => $entry->customer_name,
                    'customer_search' => $entry->customer_name,
                    'location'        => filled($entry->location) ? $entry->location : $locations->get($entry->order_id, ''),
                    'qty'             => $qty,
                    'extras'          => $extras,
                    'remarks'         => $entry->remarks ?? '',
                ];
            })->toArray();
        } else {
            $this->rows = [];
        }

        // Merge daily-dish orders for this date that aren't already linked to a sheet entry
        $linkedOrderIds = collect($this->rows)->pluck('order_id')->filter()->all();
        $excludedOrderIds = collect($sheet?->excluded_order_ids ?? [])
            ->map(fn ($orderId) => (int) $orderId)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $existingOrders = Order::with(['items.menuItem'])
            ->where('is_daily_dish', 1)
            ->whereDate('scheduled_date', $this->sheetDate)
            ->whereNotIn('status', ['Cancelled'])
            ->whereNotIn('id', array_values(array_unique([...$linkedOrderIds, ...$excludedOrderIds])))
            ->orderBy('customer_name_snapshot')
            ->get();

        $locations = app(\App\Services\OrderSheet\OrderSheetLocationService::class)->forOrders($existingOrders);
        foreach ($existingOrders as $order) {
            $qty = $emptyQty;
            $extras = [];

            foreach ($order->items as $item) {
                if (! $item->menu_item_id) {
                    continue;
                }
                $colId = $menuItemToColumnId->get($item->menu_item_id);
                if ($colId) {
                    $qty[$colId] += (int) round($item->quantity);
                } else {
                    $extras[] = [
                        'menu_item_id'   => $item->menu_item_id,
                        'menu_item_name' => $item->menuItem?->name ?? $item->description_snapshot,
                        'quantity'       => (int) round($item->quantity),
                    ];
                }
            }

            $this->rows[] = [
                'row_key'         => 'order-'.$order->id,
                'db_id'           => null,
                'order_id'        => $order->id,
                'customer_id'     => $order->customer_id,
                'customer_name'   => $order->customer_name_snapshot,
                'customer_search' => $order->customer_name_snapshot,
                'location'        => $locations->get($order->id, ''),
                'qty'             => $qty,
                'extras'          => $extras,
                'remarks'         => $order->notes ?? '',
            ];
        }

        // Always ensure a blank trailing row
        $this->ensureTrailingBlankRow();
    }

    private function ensureTrailingBlankRow(int $minimum = 5): void
    {
        $blankRows = 0;
        foreach (array_reverse($this->rows) as $row) {
            if (filled($row['customer_name']) || filled($row['order_id'])) {
                break;
            }
            $blankRows++;
        }

        while ($blankRows < $minimum) {
            $this->rows[] = $this->blankRow();
            $blankRows++;
        }
    }

    public function updatedSheetDate(): void
    {
        $this->saveStatus = '';
        $this->newCustomerRow = null;
        $this->showCustomerForm = false;
        $this->activeSearchRow = null;
        $this->customerSearchTerm = '';
        $this->loadMenuItems();
        $this->loadRows();
        $this->syncBrowserState();
    }

    public function goToToday(): void
    {
        $this->sheetDate = now()->toDateString();
        $this->updatedSheetDate();
    }

    public function prevDay(): void
    {
        $this->sheetDate = \Carbon\Carbon::parse($this->sheetDate)->subDay()->toDateString();
        $this->updatedSheetDate();
    }

    public function nextDay(): void
    {
        $this->sheetDate = \Carbon\Carbon::parse($this->sheetDate)->addDay()->toDateString();
        $this->updatedSheetDate();
    }

    public function addRow(): void
    {
        $this->rows[] = $this->blankRow();
        $this->syncBrowserState(count($this->rows));
    }

    private function rowIndex(string $rowKey): ?int
    {
        foreach ($this->rows as $index => $row) {
            if (($row['row_key'] ?? null) === $rowKey) {
                return $index;
            }
        }

        return null;
    }

    #[Renderless]
    public function removeRow(string $rowKey): void
    {
        $index = $this->rowIndex($rowKey);
        if ($index === null) {
            return;
        }

        $row = $this->rows[$index];
        $dbId = $row['db_id'] ?? null;
        $orderId = $row['order_id'] ?? null;

        \Illuminate\Support\Facades\DB::transaction(function () use ($dbId, $orderId) {
            if ($orderId) {
                $sheet = OrderSheet::whereDate('sheet_date', $this->sheetDate)->lockForUpdate()->first()
                    ?? OrderSheet::create(['sheet_date' => $this->sheetDate]);
                $excludedOrderIds = collect($sheet->excluded_order_ids ?? [])
                    ->push((int) $orderId)
                    ->map(fn ($id) => (int) $id)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                $sheet->update(['excluded_order_ids' => $excludedOrderIds]);
            }

            if ($dbId) {
                OrderSheetEntry::whereKey($dbId)->delete();
            }
        });
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);

        if ($this->activeSearchRow === $index) {
            $this->activeSearchRow = null;
            $this->customerSearchTerm = '';
        }
        $this->ensureTrailingBlankRow(1);
        $this->syncBrowserState();
    }

    public function clearRow(string $rowKey): void
    {
        $index = $this->rowIndex($rowKey);
        if ($index === null) {
            return;
        }

        $qty = collect($this->menuItems)->mapWithKeys(fn ($item) => [$item['id'] => 0])->all();
        $this->rows[$index]['qty']    = $qty;
        $this->rows[$index]['extras'] = [];
        $this->rows[$index]['remarks'] = '';
        $this->syncBrowserState();
    }

    public function bump(int $index, int $menuItemId, int $delta): void
    {
        $current = (int) ($this->rows[$index]['qty'][$menuItemId] ?? 0);
        $this->rows[$index]['qty'][$menuItemId] = max(0, $current + $delta);
        $this->syncBrowserState();
    }

    // ── Customer search ──────────────────────────────────────

    public function updatedCustomerSearchTerm(): void
    {
        unset($this->customerResults);
    }

    #[Renderless]
    public function searchCustomers(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        return Customer::query()
            ->active()
            ->search($term)
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'phone', 'delivery_address'])
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'delivery_address' => $customer->delivery_address,
            ])
            ->all();
    }

    #[Renderless]
    public function selectCustomer(int $customerId, string|int|null $rowReference = null): void
    {
        $rowIndex = is_string($rowReference)
            ? $this->rowIndex($rowReference)
            : ($rowReference ?? $this->activeSearchRow);
        if ($rowIndex === null || ! isset($this->rows[$rowIndex])) {
            return;
        }
        $customer = Customer::active()->find($customerId);
        if (! $customer) {
            return;
        }
        $i = $rowIndex;
        $this->rows[$i]['customer_id']     = $customer->id;
        $this->rows[$i]['customer_name']   = $customer->name;
        $this->rows[$i]['customer_search'] = $customer->name;
        if (blank($this->rows[$i]['location'])) {
            $this->rows[$i]['location'] = $customer->delivery_address ?? '';
        }
        $this->saveStatus = '';
        $this->ensureTrailingBlankRow(1);
        $this->activeSearchRow    = null;
        $this->customerSearchTerm = '';
        $this->syncBrowserState($i + 2);
    }

    public function selectCustomerAndAppend(int $customerId, string $rowKey): void
    {
        $this->selectCustomer($customerId, $rowKey);
    }

    #[Renderless]
    public function clearCustomer(string $rowKey): void
    {
        $rowIndex = $this->rowIndex($rowKey);
        if ($rowIndex === null) {
            return;
        }

        $this->rows[$rowIndex]['customer_id']     = null;
        $this->rows[$rowIndex]['customer_name']   = '';
        $this->rows[$rowIndex]['customer_search'] = '';
        $this->syncBrowserState();
    }

    // ── Extra dish search ────────────────────────────────────

    #[Renderless]
    public function searchMenuItems(string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }

        return MenuItem::query()
            ->search($term)
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name'])
            ->map(fn (MenuItem $item) => ['id' => $item->id, 'name' => $item->name])
            ->all();
    }

    public function addExtra(string $rowKey, int $menuItemId, string $name): void
    {
        $rowIndex = $this->rowIndex($rowKey);
        if ($rowIndex === null) {
            return;
        }

        foreach ($this->rows[$rowIndex]['extras'] as $extra) {
            if ((int) $extra['menu_item_id'] === $menuItemId) {
                return;
            }
        }

        $this->rows[$rowIndex]['extras'][] = [
            'menu_item_id' => $menuItemId,
            'menu_item_name' => $name,
            'quantity' => 1,
        ];
        $this->syncBrowserState();
    }

    public function removeExtra(string $rowKey, int $extraIndex): void
    {
        $rowIndex = $this->rowIndex($rowKey);
        if ($rowIndex === null || ! isset($this->rows[$rowIndex]['extras'][$extraIndex])) {
            return;
        }
        unset($this->rows[$rowIndex]['extras'][$extraIndex]);
        $this->rows[$rowIndex]['extras'] = array_values($this->rows[$rowIndex]['extras']);
        $this->syncBrowserState();
    }

    public function bumpExtra(string $rowKey, int $extraIndex, int $delta): void
    {
        $rowIndex = $this->rowIndex($rowKey);
        if ($rowIndex === null || ! isset($this->rows[$rowIndex]['extras'][$extraIndex])) {
            return;
        }
        $current = (int) ($this->rows[$rowIndex]['extras'][$extraIndex]['quantity'] ?? 1);
        $this->rows[$rowIndex]['extras'][$extraIndex]['quantity'] = max(1, $current + $delta);
        $this->syncBrowserState();
    }

    public function openDrawer(string $rowKey): void
    {
        if ($this->rowIndex($rowKey) === null) {
            return;
        }
        $this->compactDrawerRow = $rowKey;
    }

    public function closeDrawer(): void
    {
        $this->compactDrawerRow = null;
    }

    public function toggleMobileView(): void
    {
        $this->mobileView = $this->mobileView === 'card' ? 'grid' : 'card';
        $this->compactDrawerRow = null;
    }

    public function setMobileLayout(bool $mobile): void
    {
        $this->mobileLayout = $mobile;
        $this->compactDrawerRow = null;
        $this->activeSearchRow = null;
    }

    // ── Save ────────────────────────────────────────────────

    public function exportExcel(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless(auth()->user()?->isActive() && auth()->user()->hasAnyRole(['admin', 'manager', 'staff', 'cashier']), 403);
        $this->validate(['sheetDate' => ['required', 'date_format:Y-m-d']]);

        return app(\App\Services\OrderSheet\OrderSheetExcelExport::class)
            ->download($this->sheetDate, $this->menuItems, $this->rows);
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->isActive() && auth()->user()->hasAnyRole(['admin', 'manager', 'staff', 'cashier']), 403);
        $this->saveStatus = '';
        $this->validate([
            'sheetDate' => ['required', 'date_format:Y-m-d'],
            'rows.*.customer_name' => ['nullable', 'string', 'max:255'],
            'rows.*.qty.*' => ['numeric', 'min:0'],
            'rows.*.extras.*.quantity' => ['numeric', 'min:0'],
        ]);
        \Illuminate\Support\Facades\DB::transaction(function () {
            $sheet = OrderSheet::firstOrCreate(['sheet_date' => $this->sheetDate]);
            $sheet->entries()->delete();

            foreach ($this->rows as $row) {
                if (blank($row['customer_name'])) {
                    continue;
                }
                $entry = $sheet->entries()->create([
                    'customer_id'   => $row['customer_id'],
                    'customer_name' => $row['customer_name'],
                    'location'      => $row['location'] ?: null,
                    'remarks'       => $row['remarks'] ?: null,
                    'order_id'      => $row['order_id'] ?? null,
                ]);

                foreach ($row['qty'] as $menuItemId => $qty) {
                    if ($qty > 0) {
                        $entry->quantities()->create([
                            'daily_dish_menu_item_id' => $menuItemId,
                            'quantity'                => $qty,
                        ]);
                    }
                }

                foreach ($row['extras'] as $extra) {
                    if (($extra['quantity'] ?? 0) > 0) {
                        $entry->extras()->create([
                            'menu_item_id'   => $extra['menu_item_id'],
                            'menu_item_name' => $extra['menu_item_name'],
                            'quantity'       => $extra['quantity'],
                        ]);
                    }
                }
            }

        });
        $this->loadRows();
        $this->saveStatus = __('Sheet saved at :time. Use Publish to create or update orders.', ['time' => now()->format('H:i:s')]);
        $this->syncBrowserState();
    }

    public function publish(): void
    {
        // Save first to persist any unsaved changes
        $this->save();

        $sheet = OrderSheet::with([
            'entries.quantities.dailyDishMenuItem',
            'entries.extras',
        ])->whereDate('sheet_date', $this->sheetDate)->first();

        if (! $sheet) {
            return;
        }

        $service = app(OrderSheetPublishService::class);
        ['created' => $created, 'updated' => $updated] = $service->publish($sheet, auth()->id());

        $this->loadRows();

        $parts = [];
        if ($created > 0) $parts[] = "{$created} order" . ($created === 1 ? '' : 's') . " created";
        if ($updated > 0) $parts[] = "{$updated} order" . ($updated === 1 ? '' : 's') . " updated";

        $this->saveStatus = $parts ? implode(', ', $parts) . '.' : __('All entries already up to date.');
        $this->syncBrowserState();
    }

    private function syncBrowserState(?int $minimumVisibleRows = null): void
    {
        $dishTotals = $this->dishTotals();
        $rowTotals = collect($this->rows)->mapWithKeys(fn ($row) => [
            $row['row_key'] => array_sum($row['qty']) + collect($row['extras'])->sum('quantity'),
        ])->all();
        $minimumVisibleRows ??= collect($this->rows)
            ->takeUntil(fn ($row) => blank($row['customer_name']) && blank($row['order_id']))
            ->count() + 1;

        $this->dispatch('order-sheet-state',
            sheetDate: $this->sheetDate,
            totalItems: array_sum($dishTotals) + collect($this->extraTotals())->sum('qty'),
            dishTotals: $dishTotals,
            rowTotals: $rowTotals,
            quantities: collect($this->rows)->mapWithKeys(fn ($row) => [$row['row_key'] => $row['qty']])->all(),
            customerDrafts: collect($this->rows)->mapWithKeys(fn ($row) => [$row['row_key'] => $row['customer_search']])->all(),
            locations: collect($this->rows)->mapWithKeys(fn ($row) => [$row['row_key'] => $row['location']])->all(),
            remarks: collect($this->rows)->mapWithKeys(fn ($row) => [$row['row_key'] => $row['remarks']])->all(),
            rowIndexes: collect($this->rows)->mapWithKeys(fn ($row, $index) => [$row['row_key'] => $index])->all(),
            totalRows: count($this->rows),
            minimumVisibleRows: min($minimumVisibleRows, count($this->rows)),
        );
    }

    #[Computed]
    public function unpublishedCount(): int
    {
        return collect($this->rows)->filter(fn ($r) => filled($r['customer_name']) && empty($r['order_id']))->count();
    }

    #[Computed]
    public function customerResults(): \Illuminate\Support\Collection
    {
        if ($this->activeSearchRow === null || blank($this->customerSearchTerm)) {
            return collect();
        }
        return Customer::query()
            ->active()
            ->search($this->customerSearchTerm)
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'phone', 'customer_code']);
    }

    #[Computed]
    public function dishTotals(): array
    {
        $totals = [];
        foreach ($this->menuItems as $item) {
            $totals[$item['id']] = collect($this->rows)->sum(fn ($r) => (int) ($r['qty'][$item['id']] ?? 0));
        }
        return $totals;
    }

    #[Computed]
    public function extraTotals(): array
    {
        $totals = [];
        foreach ($this->rows as $row) {
            foreach ($row['extras'] as $extra) {
                $key = $extra['menu_item_id'];
                $totals[$key] = $totals[$key] ?? ['name' => $extra['menu_item_name'], 'qty' => 0];
                $totals[$key]['qty'] += $extra['quantity'];
            }
        }
        return $totals;
    }
} ?>

<div wire:key="order-sheet-page" wire:ignore.self data-order-sheet-page
     class="min-h-0 py-3 px-4 sm:px-8" style="background:#f5f3ee; font-family: Inter, ui-sans-serif, system-ui, sans-serif;"
     x-data="{
         openRow: null,
         visibleRows: @js(collect($rows)->takeUntil(fn ($row) => blank($row['customer_name']) && blank($row['order_id']))->count() + 1),
         totalRows: @js(count($rows)),
         currentSheetDate: @js($sheetDate),
         totalItems: @js(array_sum($this->dishTotals) + collect($this->extraTotals)->sum('qty')),
         dishTotals: @js($this->dishTotals),
         rowTotals: @js(collect($rows)->mapWithKeys(fn ($row) => [$row['row_key'] => array_sum($row['qty']) + collect($row['extras'])->sum('quantity')])),
         quantities: @js(collect($rows)->mapWithKeys(fn ($row) => [$row['row_key'] => $row['qty']])),
         customerDrafts: @js(collect($rows)->mapWithKeys(fn ($row) => [$row['row_key'] => $row['customer_search']])),
         locations: @js(collect($rows)->mapWithKeys(fn ($row) => [$row['row_key'] => $row['location']])),
         remarks: @js(collect($rows)->mapWithKeys(fn ($row) => [$row['row_key'] => $row['remarks']])),
         rowIndexes: @js(collect($rows)->mapWithKeys(fn ($row, $index) => [$row['row_key'] => $index])),
         removedRows: {},
         customerMatches: [],
         customerMatchRow: null,
         customerSearchRequest: 0,
         customerDropdownX: 0,
         customerDropdownY: 0,
         extraSearchRow: null,
         extraSearchTerm: '',
         extraMatches: [],
         extraSearchRequest: 0,
         extraDropdownX: 0,
         extraDropdownY: 0,
         async loadCustomerResults(rowKey, term, input) {
             const rowIndex = this.rowIndexes[rowKey];
             if (rowIndex === undefined) return;
             this.$wire.set(`rows.${rowIndex}.customer_search`, term, false);
             this.customerMatchRow = rowKey;
             const rect = input.getBoundingClientRect();
             this.customerDropdownX = rect.left;
             this.customerDropdownY = rect.bottom + 2;
             const request = ++this.customerSearchRequest;
             if (!term.trim()) {
                 this.customerMatches = [];
                 return;
             }
             const matches = await this.$wire.searchCustomers(term);
             if (request === this.customerSearchRequest && this.customerMatchRow === rowKey) {
                 this.customerMatches = matches;
             }
         },
         openExtraSearch(rowKey, trigger) {
             const rect = trigger.getBoundingClientRect();
             this.extraSearchRow = rowKey;
             this.extraSearchTerm = '';
             this.extraMatches = [];
             this.extraDropdownX = Math.max(8, Math.min(rect.left, window.innerWidth - 288));
             this.extraDropdownY = Math.max(8, Math.min(rect.bottom + 4, window.innerHeight - 320));
             this.$nextTick(() => this.$refs.extraSearchInput?.focus());
         },
         async loadExtraResults() {
             const request = ++this.extraSearchRequest;
             const term = this.extraSearchTerm.trim();
             if (term.length < 2) {
                 this.extraMatches = [];
                 return;
             }
             const matches = await this.$wire.searchMenuItems(term);
             if (request === this.extraSearchRequest) this.extraMatches = matches;
         },
         chooseExtra(item) {
             const rowKey = this.extraSearchRow;
             const root = document.querySelector('[data-order-sheet-page]');
             const scrollContainer = root.querySelector('.order-sheet-scroll');
             const scrollTop = scrollContainer?.scrollTop ?? 0;
             const scrollLeft = scrollContainer?.scrollLeft ?? 0;
             const pageX = window.scrollX;
             const pageY = window.scrollY;
             this.extraSearchRow = null;
             this.extraSearchTerm = '';
             this.extraMatches = [];
             this.$wire.addExtra(rowKey, item.id, item.name)
                 .then(() => this.restoreScroll(root, scrollTop, scrollLeft, pageX, pageY));
         },
         chooseCustomer(customer, rowKey) {
             const needsRenderedBlank = this.rowIndexes[rowKey] === this.totalRows - 1;
             this.customerDrafts[rowKey] = customer.name;
             if (!String(this.locations[rowKey] || '').trim()) {
                 this.locations[rowKey] = customer.delivery_address || '';
             }
             this.customerMatches = [];
             this.customerMatchRow = null;
             if (needsRenderedBlank) {
                 this.preserveScroll(() => this.$wire.selectCustomerAndAppend(customer.id, rowKey));
             } else {
                 this.$wire.selectCustomer(customer.id, rowKey);
             }
         },
         revealRow() {
             if (this.visibleRows < this.totalRows) {
                 this.visibleRows++;
                 this.$nextTick(() => this.fitSheet());
                 return;
             }
             this.$wire.addRow();
         },
         quantity(rowKey, itemId) {
             return Number(this.quantities[rowKey]?.[itemId] || 0);
         },
         updateRowField(rowKey, field, value) {
             const rowIndex = this.rowIndexes[rowKey];
             if (rowIndex === undefined) return;
             this.$wire.set(`rows.${rowIndex}.${field}`, value, false);
         },
         adjustQuantity(rowKey, itemId, delta) {
             const rowIndex = this.rowIndexes[rowKey];
             if (rowIndex === undefined) return;
             const current = this.quantity(rowKey, itemId);
             const next = Math.max(0, Number(current) + delta);
             const applied = next - Number(current);
             this.quantities[rowKey][itemId] = next;
             this.$wire.set(`rows.${rowIndex}.qty.${itemId}`, next, false);
             this.totalItems += applied;
             this.dishTotals[itemId] = Number(this.dishTotals[itemId] || 0) + applied;
             this.rowTotals[rowKey] = Number(this.rowTotals[rowKey] || 0) + applied;
             return next;
         },
         isRowVisible(rowKey, rowIndex) {
             return !this.removedRows[rowKey] && rowIndex < this.visibleRows;
         },
         rowHasContent(rowKey) {
             return Boolean(String(this.customerDrafts[rowKey] || '').trim()
                 || String(this.locations[rowKey] || '').trim()
                 || String(this.remarks[rowKey] || '').trim()
                 || Number(this.rowTotals[rowKey] || 0));
         },
         removeRowImmediately(rowKey) {
             if (!this.rowHasContent(rowKey) || this.removedRows[rowKey] || this.rowIndexes[rowKey] === undefined) return;
             const rowElements = [...document.querySelectorAll('[data-order-sheet-row]')]
                 .filter((element) => element.dataset.orderSheetRow === rowKey);
             const previousTotal = this.totalItems;
             const previousDishTotals = { ...this.dishTotals };
             this.removedRows = { ...this.removedRows, [rowKey]: true };
             rowElements.forEach((element) => element.style.display = 'none');
             this.totalItems = Math.max(0, this.totalItems - Number(this.rowTotals[rowKey] || 0));
             Object.entries(this.quantities[rowKey] || {}).forEach(([itemId, quantity]) => {
                 this.dishTotals[itemId] = Math.max(0, Number(this.dishTotals[itemId] || 0) - Number(quantity || 0));
             });
             this.$wire.removeRow(rowKey).catch(() => {
                 const restoredRows = { ...this.removedRows };
                 delete restoredRows[rowKey];
                 this.removedRows = restoredRows;
                 rowElements.forEach((element) => element.style.display = '');
                 this.totalItems = previousTotal;
                 this.dishTotals = previousDishTotals;
             });
         },
         restoreScroll(root, scrollTop, scrollLeft, pageX, pageY) {
             const restore = () => {
                 this.fitSheet();
                 const currentScrollContainer = root.querySelector('.order-sheet-scroll');
                 if (currentScrollContainer) {
                     currentScrollContainer.scrollTop = scrollTop;
                     currentScrollContainer.scrollLeft = scrollLeft;
                 }
                 window.scrollTo(pageX, pageY);
             };
             this.$nextTick(() => {
                 restore();
                 requestAnimationFrame(() => {
                     restore();
                     requestAnimationFrame(restore);
                 });
                 setTimeout(restore, 100);
             });
         },
         preserveScroll(action) {
             const root = document.querySelector('[data-order-sheet-page]');
             const scrollContainer = root.querySelector('.order-sheet-scroll');
             const scrollTop = scrollContainer?.scrollTop ?? 0;
             const scrollLeft = scrollContainer?.scrollLeft ?? 0;
             const pageX = window.scrollX;
             const pageY = window.scrollY;
             return Promise.resolve(action()).then(() => this.restoreScroll(root, scrollTop, scrollLeft, pageX, pageY));
         },
         isMobile: @js($mobileLayout),
         onResize: null,
         fitSheet() {
             const sheetShell = document.querySelector('[data-order-sheet-page] [x-ref=sheetShell]');
             if (this.isMobile || !sheetShell) return;
             const top = sheetShell.getBoundingClientRect().top + window.scrollY;
             sheetShell.style.height = Math.max(300, window.innerHeight - top - 44) + 'px';
         },
         refreshSheetLayout() {
             const refresh = () => this.fitSheet();
             this.$nextTick(() => {
                 refresh();
                 requestAnimationFrame(refresh);
                 setTimeout(refresh, 100);
             });
         },
         init() {
             this.onResize = () => {
                 const nextMobile = window.innerWidth < 768;
                 if (nextMobile !== this.isMobile) {
                     this.isMobile = nextMobile;
                     this.$wire.setMobileLayout(nextMobile);
                 }
                 this.$nextTick(() => this.fitSheet());
             };
             window.addEventListener('resize', this.onResize);
             this.$nextTick(() => {
                 this.onResize();
                 this.refreshSheetLayout();
             });
         },
         destroy() {
             window.removeEventListener('resize', this.onResize);
         }
     }"
     x-on:order-sheet-state.window="
         const changedDate = currentSheetDate !== $event.detail.sheetDate;
         currentSheetDate = $event.detail.sheetDate;
         totalItems = $event.detail.totalItems;
         dishTotals = $event.detail.dishTotals;
         rowTotals = $event.detail.rowTotals;
         quantities = $event.detail.quantities;
         customerDrafts = $event.detail.customerDrafts;
         locations = $event.detail.locations;
         remarks = $event.detail.remarks;
         rowIndexes = $event.detail.rowIndexes;
         totalRows = $event.detail.totalRows;
         visibleRows = changedDate
             ? $event.detail.minimumVisibleRows
             : Math.min(Math.max(visibleRows, $event.detail.minimumVisibleRows), totalRows);
         if (changedDate) removedRows = {};
         refreshSheetLayout();
     "
>

    <style>
        .font-hand { font-family: 'Times New Roman', Times, serif; font-weight: 700; }
        .ledger-paper {
            background: radial-gradient(1200px 400px at 20% -10%, rgba(0,0,0,0.04), transparent 70%), #fbfaf5;
            box-shadow: 0 30px 60px -30px rgba(60,50,30,0.25);
        }
        .stepper-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px; border-radius: 9999px;
            border: 1px solid #d4d4d8; background: white;
            transition: background 0.1s; cursor: pointer;
        }
        .stepper-btn:disabled { opacity: 0.3; cursor: default; }
        .stepper-btn:not(:disabled):hover { background: #f4f4f5; }
        .mobile-stepper-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 9999px; border: 1px solid #d4d4d8;
            background: white; cursor: pointer; transition: background 0.1s;
        }
        .mobile-stepper-btn:disabled { opacity: 0.3; }
        .mobile-stepper-btn.inc { background: #18181b; border-color: #18181b; color: white; }
        .mobile-stepper-btn.inc:active { background: #3f3f46; }
        /* compact grid */
        .os-grid-btn { display:flex; align-items:center; justify-content:center; width:100%; aspect-ratio:1; border-radius:6px; font-size:13px; font-weight:700; font-variant-numeric:tabular-nums; border:none; cursor:pointer; transition:background 0.1s; }
        .os-grid-btn.empty { background:#f4f4f5; color:#a1a1aa; }
        .os-grid-btn.filled { color:#fff; }
        .order-sheet-scroll {
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
        }
        .order-sheet-scroll thead th {
            position: sticky;
            top: 0;
            z-index: 20;
            background: #f8f7f2;
        }
        /* Help Bot trigger is in our top bar — hide the floating one on this page */
        [x-data*="helpBotWidget"] > button:first-child { display: none !important; }
        @@media print {
            .no-print { display: none !important; }
            body { background: white; }
            [x-ref="sheetShell"] { height: auto !important; display: block !important; }
            [x-ref="sheetShell"] > .overflow-auto { overflow: visible !important; }
        }
    </style>

    <div class="max-w-[1320px] mx-auto">
        <div class="no-print space-y-3">
            <flux:modal wire:model="showCustomerForm" class="max-w-md">
                <form wire:submit="createCustomer" class="rounded-lg border bg-white p-4 space-y-3">
                    <h2 class="font-semibold text-zinc-900">{{ __('Create customer and add to sheet') }}</h2>
                    <flux:input wire:model="newCustomerName" :label="__('Name')" required maxlength="255" />
                    <flux:input wire:model="newCustomerPhone" :label="__('Phone number')" type="tel" required maxlength="50" />
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="createCustomer">{{ __('Create and add') }}</flux:button>
                    <flux:button type="button" wire:click="$set('showCustomerForm', false)">{{ __('Cancel') }}</flux:button>
                </form>
            </flux:modal>
        </div>


        {{-- ── Top bar ── --}}
        <div x-ref="sheetToolbar" class="no-print sticky top-0 z-40 flex flex-wrap items-center justify-end gap-2 mb-3 bg-[#f5f3ee] py-1">
            <div class="w-full space-y-2">
            <div wire:loading wire:target="save,publish" role="status" class="rounded-lg bg-blue-50 p-3 text-blue-900">{{ __('Saving your sheet…') }}</div>
            @if ($saveStatus)
                <div role="status" class="rounded-lg bg-emerald-50 p-3 text-emerald-900">{{ $saveStatus }}</div>
            @endif
            @if ($errors->any())
                <div role="alert" class="rounded-lg bg-red-50 p-3 text-red-900">
                    @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
                </div>
            @endif
            </div>
            <div class="flex w-full items-center justify-end gap-2 flex-wrap">
            @if ($this->canCreateCustomer())
                <flux:button wire:click="startCustomerCreation" icon="user-plus">{{ __('New customer') }}</flux:button>
            @endif

                {{-- Day navigation --}}
                <div class="flex items-center bg-white border border-zinc-200 rounded-lg overflow-hidden">
                    <button wire:click="prevDay" class="px-2 py-2 hover:bg-zinc-50 border-r border-zinc-200" title="Previous day">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    </button>
                    <div class="flex items-center gap-2 px-3 py-2">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-zinc-500"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        <input type="date" wire:model.live="sheetDate" class="text-[13px] font-medium focus:outline-none bg-transparent" />
                    </div>
                    <button wire:click="nextDay" class="px-2 py-2 hover:bg-zinc-50 border-l border-zinc-200" title="Next day">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg>
                    </button>
                </div>

                @if ($sheetDate !== now()->toDateString())
                    <button wire:click="goToToday"
                        class="px-3 py-2 text-[13px] font-medium text-zinc-600 border border-zinc-200 bg-white rounded-lg hover:bg-zinc-50">
                        Today
                    </button>
                @endif

                <button wire:click="exportExcel" wire:loading.attr="disabled"
                    class="no-print min-h-[44px] flex items-center gap-2 px-3 py-2 bg-white border border-zinc-200 rounded-lg text-[13px] font-medium hover:bg-zinc-50 disabled:opacity-60">
                    <span wire:loading.remove wire:target="exportExcel">{{ __('Export Excel') }}</span>
                    <span wire:loading wire:target="exportExcel">{{ __('Exporting…') }}</span>
                </button>
                <button onclick="exportPDF()" class="no-print flex items-center gap-2 px-3 py-2 bg-white border border-zinc-200 rounded-lg text-[13px] font-medium hover:bg-zinc-50">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Print
                </button>
                <a href="{{ route('order-sheet.print.by-order') . '?date=' . $sheetDate }}" target="_blank"
                   class="no-print flex items-center gap-2 px-3 py-2 bg-white border border-zinc-200 rounded-lg text-[13px] font-medium hover:bg-zinc-50">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    By Order
                </a>
                <a href="{{ route('order-sheet.print.by-item') . '?date=' . $sheetDate }}" target="_blank"
                   class="no-print flex items-center gap-2 px-3 py-2 bg-white border border-zinc-200 rounded-lg text-[13px] font-medium hover:bg-zinc-50">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Item Totals
                </a>
                <button wire:click="save" wire:loading.attr="disabled" wire:target="save"
                    class="flex items-center gap-2 px-3 py-2 bg-white border border-zinc-200 rounded-lg text-[13px] font-medium hover:bg-zinc-50 disabled:opacity-60">
                    <svg wire:loading.remove wire:target="save" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <svg wire:loading wire:target="save" class="animate-spin" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>
                    <span wire:loading.remove wire:target="save">Save</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
                <button wire:click="publish" wire:loading.attr="disabled" wire:target="publish,save"
                    class="relative flex items-center gap-2 px-3 py-2 rounded-lg text-[13px] font-medium disabled:opacity-60
                           {{ $this->unpublishedCount > 0 ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-zinc-900 text-white hover:bg-zinc-800' }}">
                    <svg wire:loading.remove wire:target="publish" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 2 11 13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    <svg wire:loading wire:target="publish" class="animate-spin" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>
                    <span wire:loading.remove wire:target="publish">Publish orders{{ $this->unpublishedCount > 0 ? '' : '' }}</span>
                    <span wire:loading wire:target="publish">Publishing…</span>
                    @if ($this->unpublishedCount > 0)
                        <span wire:loading.remove wire:target="publish" class="ml-1 inline-flex items-center justify-center w-4 h-4 rounded-full bg-white/30 text-[10px] font-bold">{{ $this->unpublishedCount }}</span>
                    @endif
                </button>
            </div>
        </div>

        <template x-if="extraSearchRow">
            <div class="no-print fixed z-[9999] w-[280px] overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-xl"
                :style="`top:${extraDropdownY}px;left:${extraDropdownX}px`"
                x-on:click.outside="extraSearchRow = null; extraSearchTerm = ''; extraMatches = []">
                <div class="border-b border-zinc-100 p-2">
                    <input x-ref="extraSearchInput" x-model="extraSearchTerm"
                        x-on:input.debounce.200ms="loadExtraResults()"
                        placeholder="Search dishes…" autocomplete="off"
                        class="w-full rounded-md border border-zinc-200 px-3 py-2 text-sm focus:border-zinc-900 focus:outline-none" />
                </div>
                <div class="max-h-60 overflow-y-auto p-1">
                    <template x-for="item in extraMatches" :key="item.id">
                        <button type="button" x-on:click="chooseExtra(item)"
                            class="w-full rounded-md px-3 py-2 text-left text-sm font-medium text-zinc-900 hover:bg-zinc-50"
                            x-text="item.name"></button>
                    </template>
                    <div x-show="extraSearchTerm.trim().length >= 2 && extraMatches.length === 0"
                        class="px-3 py-4 text-center text-xs text-zinc-400">No matching dishes</div>
                    <div x-show="extraSearchTerm.trim().length < 2"
                        class="px-3 py-4 text-center text-xs text-zinc-400">Type at least 2 characters</div>
                </div>
            </div>
        </template>

        @if (empty($menuItems))
            <div class="mb-4 flex items-center gap-3 px-4 py-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                No daily dish menu found for {{ \Carbon\Carbon::parse($sheetDate)->format('d M Y') }}.
                Dish columns will be empty — you can still record customer extras.
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════
             DESKTOP VIEW (md+)
             ══════════════════════════════════════════════════ --}}
        @if (! $mobileLayout)
        <div>
            <div x-ref="sheetShell" class="ledger-paper rounded-sm relative flex flex-col min-h-0">
                <div class="absolute top-0 right-0 w-20 h-20 overflow-hidden pointer-events-none" style="clip-path:polygon(100% 0,0 0,100% 100%);background:rgba(0,0,0,0.03)"></div>

                <div class="flex shrink-0 items-center justify-between px-4 pt-3 pb-2">
                    <div>
                        <div class="text-[10px] uppercase tracking-[0.25em] text-zinc-500">Order Sheet</div>
                        <div class="font-hand text-2xl text-red-700 leading-tight">
                            {{ \Carbon\Carbon::parse($sheetDate)->format('D, d M Y') }}
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-[10px] uppercase tracking-[0.25em] text-zinc-500">Total items</div>
                        <div class="font-hand text-2xl text-zinc-800" x-text="totalItems">
                            {{ array_sum($this->dishTotals) + collect($this->extraTotals)->sum('qty') }}
                        </div>
                    </div>
                </div>

                <div class="order-sheet-scroll px-4 pb-4 min-h-0 flex-1 overflow-auto">
                    <table class="w-full border-collapse" style="min-width: 880px;">
                        <thead class="bg-[#f5f3ee]">
                            <tr>
                                <th class="border border-zinc-300 bg-white/60 align-bottom p-2 h-[120px] min-w-[220px]">
                                    <div class="text-left text-[11px] uppercase tracking-[0.15em] font-semibold text-zinc-600">Customer</div>
                                    <div class="text-right text-[10px] text-zinc-400 mt-1">
                                        {{ collect($rows)->filter(fn($r) => filled($r['customer_name']))->count() }} {{ __('entries') }}
                                    </div>
                                </th>
                                <th class="border border-zinc-300 bg-white/60 align-bottom p-1 h-[120px] w-[80px]">
                                    <div class="col-label text-[11px] uppercase tracking-[0.15em] font-semibold text-zinc-600 mx-auto"
                                         style="writing-mode:vertical-rl;transform:rotate(180deg)">Location</div>
                                </th>
                                @foreach ($menuItems as $item)
                                    <th class="border border-zinc-300 bg-white/60 align-bottom p-1 h-[120px] w-[80px]">
                                        <div class="flex flex-col items-center justify-end h-full pb-1">
                                            <div class="font-hand text-[18px] text-red-700 leading-none whitespace-nowrap"
                                                 style="writing-mode:vertical-rl;transform:rotate(180deg)">
                                                {{ $item['name'] }}
                                            </div>
                                        </div>
                                    </th>
                                @endforeach
                                <th class="border border-zinc-300 bg-white/60 align-bottom p-2 h-[120px] min-w-[240px]">
                                    <div class="text-left text-[11px] uppercase tracking-[0.15em] font-semibold text-zinc-600">Other dishes</div>
                                </th>
                                <th class="border border-zinc-300 bg-white/60 align-bottom p-1 h-[120px] w-[130px]">
                                    <div class="text-[11px] uppercase tracking-[0.15em] font-semibold text-zinc-600 mx-auto"
                                         style="writing-mode:vertical-rl;transform:rotate(180deg)">Remarks</div>
                                </th>
                                <th class="no-print w-[44px]"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $i => $row)
                                @php $rowKey = $row['row_key']; @endphp
                                <tr wire:key="desktop-row-{{ $sheetDate }}-{{ $rowKey }}" data-order-sheet-row="{{ $rowKey }}" class="group {{ blank($row['customer_name']) ? 'no-print' : '' }}" x-show="isRowVisible(@js($rowKey), {{ $i }})">

                                    {{-- Customer --}}
                                    <td class="border border-zinc-300 px-3 py-2">
                                        @if ($row['order_id'] ?? null)
                                            <div class="text-[9px] uppercase tracking-wider text-emerald-700 font-semibold mb-0.5 flex items-center gap-1">
                                                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                                Order #{{ $row['order_id'] }}
                                            </div>
                                        @endif
                                        <div class="flex items-center gap-1">
                                            <input x-model="customerDrafts[@js($rowKey)]"
                                                x-on:input.debounce.250ms="loadCustomerResults(@js($rowKey), customerDrafts[@js($rowKey)], $el)"
                                                data-order-sheet-customer-search
                                                placeholder="Search customer…"
                                                autocomplete="off"
                                                class="flex-1 min-w-0 bg-transparent focus:outline-none font-hand text-[20px] text-blue-700 leading-none placeholder:text-zinc-300 placeholder:font-sans placeholder:text-[13px]" />
                                            <button x-show="customerDrafts[@js($rowKey)]"
                                                x-on:click="customerDrafts[@js($rowKey)] = ''; $wire.clearCustomer(@js($rowKey))"
                                                class="no-print text-zinc-300 hover:text-red-500 p-0.5">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                        <template x-if="customerMatchRow === @js($rowKey) && customerMatches.length > 0">
                                            <div x-on:click.outside="customerMatches = []; customerMatchRow = null"
                                                 :style="`top:${customerDropdownY}px; left:${customerDropdownX}px;`"
                                                 class="fixed z-[9999] w-[260px] bg-white border border-zinc-200 rounded-md shadow-lg overflow-hidden">
                                                <div class="max-h-52 overflow-y-auto">
                                                    <template x-for="customer in customerMatches" :key="customer.id">
                                                        <button type="button" x-on:click="chooseCustomer(customer, @js($rowKey))"
                                                            class="w-full px-3 py-2 text-left text-sm hover:bg-zinc-50">
                                                            <div class="font-medium text-zinc-900" x-text="customer.name"></div>
                                                            <div x-show="customer.phone" class="text-xs text-zinc-500" x-text="customer.phone"></div>
                                                        </button>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
                                    </td>

                                    {{-- Location --}}
                                    <td class="border border-zinc-300 px-2 py-2">
                                        <input x-model="locations[@js($rowKey)]"
                                            x-on:change="updateRowField(@js($rowKey), 'location', locations[@js($rowKey)])"
                                            placeholder="—"
                                            class="w-full bg-transparent focus:outline-none text-[13px] text-zinc-700 text-center" />
                                    </td>

                                    {{-- Dish quantities --}}
                                    @foreach ($menuItems as $item)
                                        <td class="border border-zinc-300 px-1 py-2 text-center">
                                            <div class="inline-flex items-center gap-0.5 py-0.5">
                                                <button class="stepper-btn no-print"
                                                    x-on:click="adjustQuantity(@js($rowKey), {{ $item['id'] }}, -1)"
                                                    x-bind:disabled="quantity(@js($rowKey), {{ $item['id'] }}) === 0">
                                                    <span aria-hidden="true">−</span>
                                                </button>
                                                <span class="inline-block w-5 text-center font-semibold tabular-nums text-[13px]"
                                                    x-bind:class="quantity(@js($rowKey), {{ $item['id'] }}) === 0 ? 'text-zinc-300' : 'text-zinc-900'"
                                                    x-text="quantity(@js($rowKey), {{ $item['id'] }})">
                                                </span>
                                                <button class="stepper-btn no-print"
                                                    x-on:click="adjustQuantity(@js($rowKey), {{ $item['id'] }}, 1)">
                                                    <span aria-hidden="true">+</span>
                                                </button>
                                            </div>
                                        </td>
                                    @endforeach

                                    {{-- Extras --}}
                                    <td class="border border-zinc-300 px-2 py-2">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            @foreach ($row['extras'] as $ei => $extra)
                                                <div class="inline-flex items-center gap-1 bg-amber-50 border border-amber-200 rounded-md pl-2 pr-1 py-0.5">
                                                    <span class="text-[12px] text-amber-900 font-medium">{{ $extra['menu_item_name'] }}</span>
                                                    <span class="text-zinc-300 text-[11px]">×</span>
                                                    <div class="inline-flex items-center gap-0.5">
                                                        <button class="stepper-btn no-print" style="width:16px;height:16px"
                                                            x-on:click="preserveScroll(() => $wire.bumpExtra(@js($rowKey), {{ $ei }}, -1))"
                                                            @disabled($extra['quantity'] <= 1)>
                                                            <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/></svg>
                                                        </button>
                                                        <span class="text-[12px] font-semibold tabular-nums w-4 text-center">{{ $extra['quantity'] }}</span>
                                                        <button class="stepper-btn no-print" style="width:16px;height:16px"
                                                            x-on:click="preserveScroll(() => $wire.bumpExtra(@js($rowKey), {{ $ei }}, 1))">
                                                            <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                                        </button>
                                                    </div>
                                                    <button x-on:click="preserveScroll(() => $wire.removeExtra(@js($rowKey), {{ $ei }}))"
                                                        class="no-print text-zinc-400 hover:text-red-600 p-0.5" title="Remove">
                                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                                    </button>
                                                </div>
                                            @endforeach
                                            <button x-on:click.stop="openExtraSearch(@js($rowKey), $el)"
                                                class="no-print inline-flex items-center gap-0.5 px-1.5 py-0.5 text-[11px] text-zinc-500 hover:text-zinc-900 border border-dashed border-zinc-300 hover:border-zinc-500 rounded-md">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                                Add dish
                                            </button>
                                        </div>
                                    </td>

                                    {{-- Remarks --}}
                                    <td class="border border-zinc-300 px-2 py-2">
                                        <input x-model="remarks[@js($rowKey)]"
                                            x-on:change="updateRowField(@js($rowKey), 'remarks', remarks[@js($rowKey)])"
                                            placeholder="—"
                                            class="w-full bg-transparent focus:outline-none text-[12px] text-zinc-600 font-hand" />
                                    </td>

                                    {{-- Row actions --}}
                                    <td class="no-print px-1 text-center">
                                        <div class="flex flex-col items-center gap-0.5 opacity-0 group-hover:opacity-100 transition">
                                            <button x-on:click="preserveScroll(() => $wire.clearRow(@js($rowKey)))" title="Clear row"
                                                class="p-1 rounded hover:bg-amber-50 text-zinc-400 hover:text-amber-700">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2M19 6l-.867 12.142A2 2 0 0116.138 20H7.862a2 2 0 01-1.995-1.858L5 6"/></svg>
                                            </button>
                                            <button x-on:click="removeRowImmediately(@js($rowKey))" title="Delete row"
                                                x-bind:disabled="!rowHasContent(@js($rowKey))"
                                                x-bind:class="!rowHasContent(@js($rowKey)) ? 'opacity-20 cursor-default' : ''"
                                                class="p-1 rounded hover:bg-red-50 text-zinc-400 hover:text-red-600">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach

                            {{-- Totals row --}}
                            <tr>
                                <td class="border border-zinc-300 px-3 py-1.5 text-right text-[11px] uppercase tracking-[0.15em] text-zinc-500 font-semibold">Total</td>
                                <td class="border border-zinc-300"></td>
                                @foreach ($menuItems as $item)
                                    <td class="border border-zinc-300 px-1 py-1.5 text-center">
                                        <span class="font-hand text-[22px]"
                                            x-bind:class="dishTotals[{{ $item['id'] }}] > 0 ? 'text-red-700' : 'text-zinc-300'"
                                            x-text="dishTotals[{{ $item['id'] }}] > 0 ? dishTotals[{{ $item['id'] }}] : '—'">
                                            {{ ($this->dishTotals[$item['id']] ?? 0) ?: '—' }}
                                        </span>
                                    </td>
                                @endforeach
                                <td class="border border-zinc-300 px-3 py-1.5">
                                    @if (count($this->extraTotals) > 0)
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($this->extraTotals as $total)
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-amber-100 border border-amber-300 rounded-md text-[12px] text-amber-900">
                                                    {{ $total['name'] }} <strong class="tabular-nums">×{{ $total['qty'] }}</strong>
                                                </span>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-zinc-300 text-[12px]">—</span>
                                    @endif
                                </td>
                                <td class="border border-zinc-300 px-2 py-1.5 text-right">
                                    <span class="text-[10px] uppercase tracking-wider text-zinc-500">Grand: </span>
                                    <span class="font-hand text-[22px] text-red-700" x-text="totalItems || '—'">
                                        {{ array_sum($this->dishTotals) + collect($this->extraTotals)->sum('qty') ?: '—' }}
                                    </span>
                                </td>
                                <td class="no-print"></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="no-print mt-3">
                        <button x-on:click="revealRow()"
                            class="flex items-center gap-2 px-3 py-2 text-[13px] font-medium text-zinc-700 hover:text-zinc-900 border border-dashed border-zinc-300 hover:border-zinc-500 rounded-lg transition">
                            <span aria-hidden="true">+</span>
                            Add row
                        </button>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- ══════════════════════════════════════════════════
             MOBILE VIEW (<md)
             ══════════════════════════════════════════════════ --}}
        @if ($mobileLayout)
        <div style="padding-bottom: 120px;">

            {{-- Mobile sub-bar: view toggle --}}
            <div class="flex items-center gap-1 bg-zinc-100 rounded-lg p-0.5 mb-3 self-start">
                <button wire:click="toggleMobileView"
                    class="px-2.5 py-1 rounded-md text-[12px] font-semibold transition {{ $mobileView === 'card' ? 'bg-white shadow-sm text-zinc-900' : 'text-zinc-500' }}">
                    Cards
                </button>
                <button wire:click="toggleMobileView"
                    class="px-2.5 py-1 rounded-md text-[12px] font-semibold transition {{ $mobileView === 'grid' ? 'bg-white shadow-sm text-zinc-900' : 'text-zinc-500' }}">
                    Grid
                </button>
            </div>

            {{-- Dish totals chips --}}
            @if (count($menuItems) > 0)
                <div class="flex gap-1.5 overflow-x-auto pb-2 mb-3" style="scrollbar-width:none">
                    @foreach ($menuItems as $item)
                        @php $t = $this->dishTotals[$item['id']] ?? 0; @endphp
                        <div class="flex-shrink-0 flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white border border-zinc-200">
                            <span class="w-1.5 h-1.5 rounded-full bg-red-600"></span>
                            <span class="text-[11px] text-zinc-600 font-medium max-w-[90px] truncate">{{ $item['name'] }}</span>
                            <span class="text-[12px] font-bold tabular-nums"
                                x-bind:class="dishTotals[{{ $item['id'] }}] > 0 ? 'text-red-700' : 'text-zinc-300'"
                                x-text="dishTotals[{{ $item['id'] }}] || 0">{{ $t }}</span>
                        </div>
                    @endforeach
                    @foreach ($this->extraTotals as $total)
                        <div class="flex-shrink-0 flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-amber-50 border border-amber-200">
                            <span class="text-[11px] text-amber-900 font-medium max-w-[90px] truncate">{{ $total['name'] }}</span>
                            <span class="text-[12px] font-bold tabular-nums text-amber-900">{{ $total['qty'] }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($mobileView === 'card')
            {{-- ── Card view ── --}}
            <div class="space-y-2">
                @foreach ($rows as $i => $row)
                    @php
                        $rowKey = $row['row_key'];
                        $sum = array_sum($row['qty']) + collect($row['extras'])->sum('quantity');
                    @endphp
                    <div wire:key="mobile-row-{{ $sheetDate }}-{{ $rowKey }}" data-order-sheet-row="{{ $rowKey }}" x-show="isRowVisible(@js($rowKey), {{ $i }})" class="bg-white rounded-xl border border-zinc-200 overflow-hidden">

                        {{-- Card header (always visible) --}}
                        <div class="flex items-center gap-2 px-3 py-2.5">
                            <button x-on:click="openRow = (openRow === @js($rowKey)) ? null : @js($rowKey)"
                                class="w-8 h-8 rounded-full bg-zinc-100 flex items-center justify-center flex-shrink-0">
                                <span class="text-[13px] font-semibold text-zinc-600">
                                    {{ strtoupper(substr($row['customer_name'] ?: '?', 0, 1)) }}
                                </span>
                            </button>

                            {{-- Customer name input (always editable inline) --}}
                            <div class="flex-1 min-w-0 relative">
                                <input x-model="customerDrafts[@js($rowKey)]"
                                    x-on:input.debounce.250ms="loadCustomerResults(@js($rowKey), customerDrafts[@js($rowKey)], $el)"
                                    data-order-sheet-customer-search
                                    placeholder="Enter customer…"
                                    autocomplete="off"
                                    class="w-full font-semibold text-[15px] bg-transparent focus:outline-none placeholder:text-zinc-300 placeholder:font-normal" />

                                <template x-if="customerMatchRow === @js($rowKey) && customerMatches.length > 0">
                                    <div x-on:click.outside="customerMatches = []; customerMatchRow = null"
                                        class="absolute left-0 top-full z-20 mt-0.5 w-[260px] bg-white border border-zinc-200 rounded-md shadow-lg overflow-hidden">
                                        <div class="max-h-52 overflow-y-auto">
                                            <template x-for="customer in customerMatches" :key="customer.id">
                                                <button type="button" x-on:click="chooseCustomer(customer, @js($rowKey))"
                                                    class="w-full px-3 py-2 text-left text-sm hover:bg-zinc-50">
                                                    <div class="font-medium text-zinc-900" x-text="customer.name"></div>
                                                    <div x-show="customer.phone" class="text-xs text-zinc-500" x-text="customer.phone"></div>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            {{-- Qty badges --}}
                            <div class="flex items-center gap-0.5 flex-shrink-0">
                                @foreach ($menuItems as $idx => $item)
                                    <span x-show="quantity(@js($rowKey), {{ $item['id'] }}) > 0"
                                        x-text="quantity(@js($rowKey), {{ $item['id'] }})"
                                        class="text-[10px] font-bold tabular-nums w-5 h-5 rounded flex items-center justify-center bg-red-600 text-white">
                                            {{ $row['qty'][$item['id']] ?? 0 }}
                                        </span>
                                @endforeach
                                @if (collect($row['extras'])->sum('quantity') > 0)
                                    <span class="text-[10px] font-bold tabular-nums w-5 h-5 rounded flex items-center justify-center bg-amber-500 text-white">
                                        +{{ collect($row['extras'])->sum('quantity') }}
                                    </span>
                                @endif
                                <span x-show="rowTotals[@js($rowKey)] === 0" class="text-[11px] text-zinc-300">—</span>
                            </div>

                            <button x-on:click="openRow = (openRow === @js($rowKey)) ? null : @js($rowKey)" class="p-1">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                     class="text-zinc-400 transition-transform" :class="openRow === @js($rowKey) ? 'rotate-90' : ''">
                                    <path d="M9 5l7 7-7 7"/>
                                </svg>
                            </button>
                        </div>

                        {{-- Card body (expanded) --}}
                        <div x-show="openRow === @js($rowKey)" x-collapse
                             class="border-t border-zinc-100 px-3.5 py-3 bg-zinc-50/50">

                            {{-- Location --}}
                            <div class="mb-3">
                                <div class="text-[10px] uppercase tracking-wider text-zinc-500 font-semibold mb-1">Location</div>
                                <input x-model="locations[@js($rowKey)]"
                                    x-on:change="updateRowField(@js($rowKey), 'location', locations[@js($rowKey)])"
                                    placeholder="—"
                                    class="w-full px-2.5 py-1.5 text-[13px] bg-white border border-zinc-200 rounded-md focus:outline-none focus:border-zinc-900" />
                            </div>

                            {{-- Dish steppers --}}
                            @if (count($menuItems) > 0)
                                <div class="space-y-1 mb-3">
                                    @foreach ($menuItems as $item)
                                        <div class="flex items-center justify-between gap-2 py-1">
                                            <div class="flex items-center gap-2 flex-1 min-w-0">
                                                <span class="w-1.5 h-1.5 rounded-full bg-red-600 flex-shrink-0"></span>
                                                <span class="text-[13px] truncate">{{ $item['name'] }}</span>
                                            </div>
                                            <div class="inline-flex items-center gap-2 flex-shrink-0">
                                                <button x-on:click="adjustQuantity(@js($rowKey), {{ $item['id'] }}, -1)"
                                                    class="mobile-stepper-btn" x-bind:disabled="quantity(@js($rowKey), {{ $item['id'] }}) === 0">
                                                    <span aria-hidden="true">−</span>
                                                </button>
                                                <span class="w-6 text-center text-[17px] font-semibold tabular-nums"
                                                    x-bind:class="quantity(@js($rowKey), {{ $item['id'] }}) === 0 ? 'text-zinc-300' : 'text-red-700'"
                                                    x-text="quantity(@js($rowKey), {{ $item['id'] }})">
                                                </span>
                                                <button x-on:click="adjustQuantity(@js($rowKey), {{ $item['id'] }}, 1)"
                                                    class="mobile-stepper-btn inc">
                                                    <span aria-hidden="true">+</span>
                                                </button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Extra dishes --}}
                            <div class="pt-3 border-t border-zinc-200/70 mb-3">
                                <div class="text-[10px] uppercase tracking-wider text-zinc-500 font-semibold mb-1.5">Other dishes</div>
                                <div class="space-y-1.5">
                                    @foreach ($row['extras'] as $ei => $extra)
                                        <div class="flex items-center gap-2 bg-amber-50 border border-amber-200 rounded-lg px-2 py-1.5">
                                            <span class="flex-1 text-[13px] text-amber-900 font-medium">{{ $extra['menu_item_name'] }}</span>
                                            <div class="inline-flex items-center gap-2">
                                                <button x-on:click="preserveScroll(() => $wire.bumpExtra(@js($rowKey), {{ $ei }}, -1))"
                                                    class="mobile-stepper-btn" style="width:28px;height:28px" @disabled($extra['quantity'] <= 1)>
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/></svg>
                                                </button>
                                                <span class="w-5 text-center text-[14px] font-semibold tabular-nums text-amber-800">{{ $extra['quantity'] }}</span>
                                                <button x-on:click="preserveScroll(() => $wire.bumpExtra(@js($rowKey), {{ $ei }}, 1))"
                                                    class="mobile-stepper-btn inc" style="width:28px;height:28px">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                                </button>
                                            </div>
                                            <button x-on:click="preserveScroll(() => $wire.removeExtra(@js($rowKey), {{ $ei }}))" class="text-zinc-400 active:text-red-600 p-1">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                    @endforeach

                                    <button x-on:click.stop="openExtraSearch(@js($rowKey), $el)"
                                        class="w-full flex items-center justify-center gap-1 py-2 text-[12px] font-medium text-amber-800 border border-dashed border-amber-300 rounded-lg bg-amber-50/40">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                        Add other dish
                                    </button>
                                </div>
                            </div>

                            {{-- Remarks --}}
                            <div class="mb-3">
                                <div class="text-[10px] uppercase tracking-wider text-zinc-500 font-semibold mb-1">Remarks</div>
                                <input x-model="remarks[@js($rowKey)]"
                                    x-on:change="updateRowField(@js($rowKey), 'remarks', remarks[@js($rowKey)])"
                                    placeholder="—"
                                    class="w-full px-2.5 py-1.5 text-[13px] bg-white border border-zinc-200 rounded-md focus:outline-none focus:border-zinc-900" />
                            </div>

                            {{-- Row actions --}}
                            <div class="pt-3 border-t border-zinc-200/70 flex items-center justify-between">
                                <span class="text-[11px] text-zinc-500"
                                    x-text="`${rowTotals[@js($rowKey)]} item${rowTotals[@js($rowKey)] === 1 ? '' : 's'}`">{{ $sum }} item{{ $sum === 1 ? '' : 's' }}</span>
                                <div class="flex items-center gap-1">
                                    <button x-on:click="preserveScroll(() => $wire.clearRow(@js($rowKey)))"
                                        class="flex items-center gap-1 text-[12px] text-amber-700 px-2 py-1 rounded-md active:bg-amber-50"
                                        x-bind:class="rowTotals[@js($rowKey)] === 0 ? 'opacity-30' : ''">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2M19 6l-.867 12.142A2 2 0 0116.138 20H7.862a2 2 0 01-1.995-1.858L5 6"/></svg>
                                        Clear
                                    </button>
                                    <button x-on:click="removeRowImmediately(@js($rowKey))"
                                        x-bind:disabled="!rowHasContent(@js($rowKey))"
                                        class="flex items-center gap-1 text-[12px] text-red-600 px-2 py-1 rounded-md active:bg-red-50">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <button x-on:click="revealRow()"
                class="mt-3 w-full flex items-center justify-center gap-2 px-3 py-3 text-[13px] font-medium text-zinc-700 bg-white border border-dashed border-zinc-300 rounded-xl active:bg-zinc-50">
                <span aria-hidden="true">+</span>
                Add row
            </button>

            @else
            {{-- ── Compact tap-grid view ── --}}
            <div class="overflow-auto max-h-[70dvh] -mx-1 px-1">
                <table class="w-full border-collapse" style="table-layout:fixed; min-width: {{ 90 + count($menuItems) * 64 + 48 }}px;">
                    <thead style="position:sticky;top:0;z-index:10;background:#f5f3ee;">
                        <tr>
                            <th class="text-left text-[10px] uppercase tracking-wider text-zinc-500 font-semibold px-1 py-2" style="width:90px;">Name</th>
                            @foreach ($menuItems as $item)
                                <th class="px-0.5 py-2 text-center" style="width:64px;">
                                    <div class="text-[13px] font-semibold leading-tight truncate {{ in_array($item['role'], ['salad','dessert']) ? 'text-emerald-700' : 'text-red-700' }}"
                                         title="{{ $item['name'] }}">
                                        {{ \Illuminate\Support\Str::limit($item['name'], 9, '') }}
                                    </div>
                                </th>
                            @endforeach
                            <th class="px-0.5 py-2 text-[13px] text-amber-700 font-semibold text-center" style="width:48px;">+</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $i => $row)
                            @php $rowKey = $row['row_key']; @endphp
                            <tr wire:key="grid-row-{{ $sheetDate }}-{{ $rowKey }}" data-order-sheet-row="{{ $rowKey }}" class="border-t border-zinc-200/60" x-show="isRowVisible(@js($rowKey), {{ $i }})">
                                <td class="px-1 py-1.5">
                                    <input x-model="customerDrafts[@js($rowKey)]"
                                        x-on:input.debounce.250ms="loadCustomerResults(@js($rowKey), customerDrafts[@js($rowKey)], $el)"
                                        data-order-sheet-customer-search
                                        placeholder="Name"
                                        autocomplete="off"
                                        class="w-full text-[12px] font-semibold bg-transparent focus:outline-none focus:bg-white rounded px-1 py-0.5 placeholder:text-zinc-300 placeholder:font-normal" />
                                    <template x-if="customerMatchRow === @js($rowKey) && customerMatches.length > 0">
                                        <div x-on:click.outside="customerMatches = []; customerMatchRow = null"
                                            class="absolute left-0 z-20 mt-0.5 w-[220px] bg-white border border-zinc-200 rounded-md shadow-lg overflow-hidden">
                                            <template x-for="customer in customerMatches" :key="customer.id">
                                                <button type="button" x-on:click="chooseCustomer(customer, @js($rowKey))"
                                                    class="w-full px-3 py-2 text-left text-sm hover:bg-zinc-50">
                                                    <div class="font-medium text-zinc-900 text-[12px]" x-text="customer.name"></div>
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                </td>
                                @foreach ($menuItems as $item)
                                    @php $q = $row['qty'][$item['id']] ?? 0; $isMain = in_array($item['role'], ['main','diet','vegetarian']); $color = $isMain ? '#dc2626' : '#059669'; @endphp
                                    <td class="px-0.5 py-1 text-center">
                                        <button x-on:click="adjustQuantity(@js($rowKey), {{ $item['id'] }}, 1)"
                                            class="os-grid-btn"
                                            x-bind:class="quantity(@js($rowKey), {{ $item['id'] }}) === 0 ? 'empty' : 'filled'"
                                            x-bind:style="quantity(@js($rowKey), {{ $item['id'] }}) > 0 ? 'background-color:{{ $color }}' : ''"
                                            x-text="quantity(@js($rowKey), {{ $item['id'] }}) === 0 ? '+' : quantity(@js($rowKey), {{ $item['id'] }})">
                                        </button>
                                            <button x-show="quantity(@js($rowKey), {{ $item['id'] }}) > 0" x-on:click="adjustQuantity(@js($rowKey), {{ $item['id'] }}, -1)"
                                                style="display:block;width:100%;margin-top:2px;font-size:11px;font-weight:700;color:{{ $color }};background:none;border:none;cursor:pointer;line-height:1;padding:1px 0;">
                                                −
                                            </button>
                                    </td>
                                @endforeach
                                <td class="px-0.5 py-1 text-center">
                                    @php $extraSum = collect($row['extras'])->sum('quantity'); @endphp
                                    <button wire:click="openDrawer(@js($rowKey))"
                                        class="os-grid-btn {{ $extraSum === 0 ? '' : 'filled' }}"
                                        style="{{ $extraSum > 0 ? 'background-color:#f59e0b;' : 'background:#fff;border:1px dashed #d4d4d8;color:#a1a1aa;' }}">
                                        {{ $extraSum === 0 ? '…' : $extraSum }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach

                        {{-- Totals --}}
                        <tr class="border-t-2 border-zinc-300">
                            <td class="px-1 py-2 text-[10px] uppercase tracking-wider text-zinc-500 font-semibold">Total</td>
                            @foreach ($menuItems as $item)
                                @php $t = $this->dishTotals[$item['id']] ?? 0; @endphp
                                <td class="px-0.5 py-2 text-center font-hand text-[18px]"
                                    x-bind:class="dishTotals[{{ $item['id'] }}] > 0 ? '{{ in_array($item['role'], ['salad', 'dessert']) ? 'text-emerald-700' : 'text-red-700' }}' : 'text-zinc-300'"
                                    x-text="dishTotals[{{ $item['id'] }}] > 0 ? dishTotals[{{ $item['id'] }}] : '—'">
                                    {{ $t > 0 ? $t : '—' }}
                                </td>
                            @endforeach
                            <td class="px-0.5 py-2 text-center font-hand text-[18px] {{ collect($this->extraTotals)->sum('qty') === 0 ? 'text-zinc-300' : 'text-amber-700' }}">
                                {{ collect($this->extraTotals)->sum('qty') ?: '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            @if (count($this->extraTotals) > 0)
                <div class="mt-2 px-1">
                    <div class="text-[9px] uppercase tracking-wider text-zinc-500 font-semibold mb-1">Other dishes</div>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($this->extraTotals as $total)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-amber-50 border border-amber-200 rounded text-[11px] text-amber-900">
                                {{ $total['name'] }} <strong class="tabular-nums">×{{ $total['qty'] }}</strong>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            <button x-on:click="revealRow()"
                class="mt-3 w-full flex items-center justify-center gap-2 px-3 py-2.5 text-[13px] font-medium text-zinc-700 bg-white border border-dashed border-zinc-300 rounded-xl active:bg-zinc-50">
                <span aria-hidden="true">+</span>
                Add row
            </button>

            <div class="mt-1.5 text-[10px] text-zinc-400 text-center">Tap = +1 · hold = −1 · "…" for extras / notes</div>

            {{-- Compact drawer --}}
            @php $di = $compactDrawerRow === null ? false : collect($rows)->search(fn ($row) => $row['row_key'] === $compactDrawerRow); @endphp
            @if ($di !== false)
                @php $dr = $rows[$di]; $drawerRowKey = $dr['row_key']; @endphp
                <div class="fixed inset-0 z-40 flex flex-col justify-end" style="background:rgba(0,0,0,0.4);"
                     wire:click.self="closeDrawer">
                    <div class="bg-white rounded-t-2xl px-4 pt-3 pb-8 max-h-[75vh] overflow-y-auto" wire:click.stop>
                        <div class="w-10 h-1 bg-zinc-200 rounded-full mx-auto mb-3"></div>
                        <div class="font-semibold text-[15px] mb-0.5">{{ $dr['customer_name'] ?: 'Unnamed' }}</div>
                        <div class="text-[11px] text-zinc-500 mb-3">Other dishes · notes · actions</div>

                        {{-- Extras --}}
                        <div class="space-y-1.5 mb-3">
                            @foreach ($dr['extras'] as $ei => $extra)
                                <div class="flex items-center gap-2 bg-amber-50 border border-amber-200 rounded-lg px-2 py-1.5">
                                    <span class="flex-1 text-[13px] text-amber-900 font-medium">{{ $extra['menu_item_name'] }}</span>
                                    <button x-on:click="preserveScroll(() => $wire.bumpExtra(@js($drawerRowKey), {{ $ei }}, -1))" class="mobile-stepper-btn" style="width:28px;height:28px" @disabled($extra['quantity']<=1)>
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/></svg>
                                    </button>
                                    <span class="w-5 text-center text-[13px] font-semibold tabular-nums">{{ $extra['quantity'] }}</span>
                                    <button x-on:click="preserveScroll(() => $wire.bumpExtra(@js($drawerRowKey), {{ $ei }}, 1))" class="mobile-stepper-btn inc" style="width:28px;height:28px">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                    </button>
                                    <button x-on:click="preserveScroll(() => $wire.removeExtra(@js($drawerRowKey), {{ $ei }}))" class="text-zinc-400 p-1">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            @endforeach
                            <button x-on:click.stop="openExtraSearch(@js($drawerRowKey), $el)"
                                class="w-full flex items-center justify-center gap-1 py-2 text-[12px] font-medium text-amber-800 border border-dashed border-amber-300 rounded-lg bg-amber-50/40">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                Add other dish
                            </button>
                        </div>

                        {{-- Remarks --}}
                        <div class="mb-4">
                            <div class="text-[10px] uppercase tracking-wider text-zinc-500 font-semibold mb-1">Remarks</div>
                            <input x-model="remarks[@js($drawerRowKey)]"
                                x-on:change="updateRowField(@js($drawerRowKey), 'remarks', remarks[@js($drawerRowKey)])"
                                placeholder="—"
                                class="w-full px-2.5 py-1.5 text-[13px] bg-white border border-zinc-200 rounded-md focus:outline-none" />
                        </div>

                        {{-- Actions --}}
                        <div class="grid grid-cols-2 gap-2">
                            <button x-on:click="preserveScroll(() => $wire.clearRow(@js($drawerRowKey))); $wire.closeDrawer()"
                                class="flex items-center justify-center gap-1 py-2.5 text-[13px] font-semibold text-amber-700 bg-amber-50 border border-amber-200 rounded-lg">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2M19 6l-.867 12.142A2 2 0 0116.138 20H7.862a2 2 0 01-1.995-1.858L5 6"/></svg>
                                Clear row
                            </button>
                            <button x-on:click="removeRowImmediately(@js($drawerRowKey)); $wire.closeDrawer()"
                                class="flex items-center justify-center gap-1 py-2.5 text-[13px] font-semibold text-white bg-red-600 rounded-lg">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            @endif

            @endif {{-- end mobileView toggle --}}

            {{-- Sticky bottom bar --}}
            <div x-show="isMobile" class="fixed left-0 right-0 bottom-0 px-4 pb-6 pt-2"
                 style="background:linear-gradient(to top,rgba(245,243,238,1) 60%,rgba(245,243,238,0))">
                <div class="rounded-2xl bg-zinc-900 text-white px-3 py-2.5 flex items-center justify-between shadow-xl gap-2">
                    <div class="min-w-0">
                        <div class="text-[10px] uppercase tracking-[0.2em] text-zinc-400">Sheet total</div>
                        <div class="text-[15px] font-semibold tabular-nums leading-tight">
                            <span x-text="totalItems">{{ array_sum($this->dishTotals) + collect($this->extraTotals)->sum('qty') }}</span> items
                            · {{ collect($rows)->filter(fn($r) => filled($r['customer_name']))->count() }} people
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button onclick="exportPDF()" class="flex items-center gap-1 bg-white/15 text-white px-2.5 py-2 rounded-full text-[12px] font-semibold">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                            PDF
                        </button>
                        <button wire:click="save" wire:loading.attr="disabled" wire:target="save"
                            class="flex items-center gap-1 bg-white/15 text-white px-2.5 py-2 rounded-full text-[12px] font-semibold disabled:opacity-60">
                            <svg wire:loading.remove wire:target="save" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            <svg wire:loading wire:target="save" class="animate-spin" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>
                            <span wire:loading.remove wire:target="save">Save</span>
                            <span wire:loading wire:target="save">Saving…</span>
                        </button>
                        <button wire:click="publish" wire:loading.attr="disabled" wire:target="publish,save"
                            class="flex items-center gap-1 px-2.5 py-2 rounded-full text-[12px] font-semibold disabled:opacity-60
                                   {{ $this->unpublishedCount > 0 ? 'bg-emerald-500 text-white' : 'bg-white text-zinc-900 active:bg-zinc-100' }}">
                            <svg wire:loading.remove wire:target="publish" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 2 11 13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            <svg wire:loading wire:target="publish" class="animate-spin" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>
                            <span wire:loading.remove wire:target="publish">Publish{{ $this->unpublishedCount > 0 ? ' ('.$this->unpublishedCount.')' : '' }}</span>
                            <span wire:loading wire:target="publish">Publishing…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>

    {{-- Print / PDF export --}}
    <script>
    function buildOrderSheetPrintTable() {
        const root = document.querySelector('[wire\\:key^="order-sheet-"]');
        const componentRoot = root?.closest('[wire\\:id]');
        const component = componentRoot ? window.Livewire?.find(componentRoot.getAttribute('wire:id')) : null;
        const rows = component?.get('rows') || [];
        const menuItems = component?.get('menuItems') || [];
        const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[character]);
        const table = document.createElement('table');
        const headings = ['Customer', 'Location', ...menuItems.map(item => item.name), 'Other dishes', 'Total', 'Remarks'];
        const printableRows = rows.filter(row => row.customer_name);
        const body = printableRows.map(row => {
            const extras = (row.extras || []).filter(extra => Number(extra.quantity) > 0);
            const quantityCells = menuItems.map(item => `<td>${Number(row.qty?.[item.id] || 0) || '—'}</td>`).join('');
            const total = Object.values(row.qty || {}).reduce((sum, quantity) => sum + Number(quantity || 0), 0)
                + extras.reduce((sum, extra) => sum + Number(extra.quantity || 0), 0);
            const extraNames = extras.map(extra => `${escapeHtml(extra.menu_item_name)} ×${Number(extra.quantity)}`).join(', ');
            return `<tr><td>${escapeHtml(row.customer_name)}</td><td>${escapeHtml(row.location)}</td>${quantityCells}<td>${extraNames || '—'}</td><td>${total || '—'}</td><td>${escapeHtml(row.remarks)}</td></tr>`;
        }).join('');
        const dishTotals = menuItems.map(item => printableRows.reduce((sum, row) => sum + Number(row.qty?.[item.id] || 0), 0));
        const extraTotals = new Map();
        printableRows.flatMap(row => row.extras || []).forEach(extra => {
            if (Number(extra.quantity) > 0) {
                extraTotals.set(extra.menu_item_name, Number(extraTotals.get(extra.menu_item_name) || 0) + Number(extra.quantity));
            }
        });
        const extraTotal = [...extraTotals.values()].reduce((sum, quantity) => sum + quantity, 0);
        const extraSummary = [...extraTotals].map(([name, quantity]) => `${escapeHtml(name)} ×${quantity}`).join(', ');
        const grandTotal = dishTotals.reduce((sum, quantity) => sum + quantity, 0) + extraTotal;
        const totals = `<tr><th>Total</th><td></td>${dishTotals.map(total => `<td>${total || '—'}</td>`).join('')}<td>${extraSummary || '—'}</td><td>${grandTotal || '—'}</td><td></td></tr>`;
        table.innerHTML = `<thead><tr>${headings.map(heading => `<th>${escapeHtml(heading)}</th>`).join('')}</tr></thead><tbody>${body}</tbody><tfoot>${totals}</tfoot>`;
        return table;
    }

    function exportPDF() {
        const win = window.open('', '_blank');
        if (!win) { alert('Allow pop-ups to export PDF.'); return; }

        const date = document.querySelector('input[type=date]')?.value || '';
        const prettyDate = date ? new Date(date + 'T00:00:00').toLocaleDateString('en-GB', {weekday:'long',day:'2-digit',month:'long',year:'numeric'}) : '';

        const table = document.querySelector('.ledger-paper table') || buildOrderSheetPrintTable();
        const populatedTable = table?.cloneNode(true);
        if (populatedTable) {
            const inputs = table.querySelectorAll('input');
            populatedTable.querySelectorAll('input').forEach((input, index) => input.setAttribute('value', inputs[index].value));
        }
        const printTable = populatedTable ? populatedTable.outerHTML
            .replace(/class="[^"]*no-print[^"]*"/g, 'style="display:none"')
            : '<p>No data</p>';

        const blankTable = table?.cloneNode(true);
        if (blankTable) {
            blankTable.querySelectorAll('.no-print, tfoot').forEach(el => el.remove());
            blankTable.querySelector('thead th div:last-child')?.remove();
            const body = blankTable.querySelector('tbody');
            const columns = blankTable.querySelectorAll('thead th').length;
            body.innerHTML = Array.from({length: 14}, () => '<tr>' + '<td>&nbsp;</td>'.repeat(columns) + '</tr>').join('');
        }
        const extraPages = blankTable ? Array.from({length: 2}, (_, index) =>
            `<section class="blank-sheet"><h2>Additional orders — ${prettyDate} (${index + 1}/2)</h2>${blankTable.outerHTML}</section>`).join('') : '';

        win.document.write(`<!doctype html><html><head><meta charset="utf-8">
        <title>Layla Kitchen — ${prettyDate}</title>
        <style>
            @page { size: A4 landscape; margin: 14mm; }
            * { box-sizing: border-box; }
            body {
                font-family: 'Times New Roman', Times, serif;
                color: #18181b;
                margin: 0;
                padding: 20px;
                font-size: 16px;
            }
            .font-hand { font-family: 'Times New Roman', Times, serif; font-weight: 700; }
            h1 { font-size: 28px; margin: 0 0 2px; }
            .sub { font-size: 13px; color: #71717a; text-transform: uppercase; letter-spacing: 0.18em; }
            .meta {
                display: flex;
                justify-content: space-between;
                align-items: flex-end;
                border-bottom: 2px solid #18181b;
                padding-bottom: 10px;
                margin-bottom: 16px;
            }
            table { width: 100%; border-collapse: collapse; font-size: 16px; }
            th, td { border: 1px solid #d4d4d8; padding: 6px 10px; vertical-align: middle; }
            thead th {
                background: #f4f4f5;
                font-size: 14px;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                font-family: 'Times New Roman', Times, serif;
            }
            .blank-sheet { break-before: page; page-break-before: always; break-inside: avoid; }
            .blank-sheet table { table-layout: fixed; font-size: 11px; }
            .blank-sheet th { min-width: 0 !important; width: auto !important; height: 30mm !important; padding: 2px; overflow-wrap: anywhere; }
            .blank-sheet td { height: 7mm; padding: 0 3px; }
            .blank-sheet h2 { font-size: 16px; margin: 0 0 8px; }
            /* Strip all input styling — show only the value text */
            input {
                border: none !important;
                outline: none !important;
                box-shadow: none !important;
                background: transparent !important;
                padding: 0 !important;
                font-family: inherit !important;
                font-size: inherit !important;
                color: inherit !important;
                width: 100% !important;
            }
            /* Hide zero-quantity cells (they carry the text-zinc-300 Tailwind class) */
            span.text-zinc-300 { visibility: hidden; }
            /* Hide spinner/stepper divs */
            .inline-flex.items-center.gap-0\\.5 button,
            .inline-flex.items-center.gap-1 button { display: none !important; }
            [style*="display:none"] { display: none !important; }
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
    }
    </script>
</div>

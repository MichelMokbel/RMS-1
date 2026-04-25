{{-- resources/views/livewire/order-sheet.blade.php --}}
<?php
use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\MealSubscription;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderSheet;
use App\Models\OrderSheetEntry;
use App\Models\OrderSheetEntryExtra;
use App\Services\OrderSheet\OrderSheetPublishService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $sheetDate = '';
    public array $menuItems = [];
    public array $rows = [];

    public string $mobileView = 'card';   // 'card' | 'grid'
    public ?int $compactDrawerRow = null;

    public ?int $activeSearchRow = null;
    public string $customerSearchTerm = '';

    public ?int $activeExtraRow = null;
    public string $extraSearchTerm = '';

    public function mount(): void
    {
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
            'entries.quantities',
            'entries.extras',
        ])->whereDate('sheet_date', $this->sheetDate)->first();

        // Lookup: menu_item_id → daily_dish_menu_item_id (for mapping order items → qty columns)
        $menuItemToColumnId = collect($this->menuItems)->keyBy('menu_item_id')->map(fn ($m) => $m['id']);
        $emptyQty = collect($this->menuItems)->mapWithKeys(fn ($item) => [$item['id'] => 0])->all();

        if ($sheet && $sheet->entries->isNotEmpty()) {
            // Sheet has been saved before — load persisted entries
            $this->rows = $sheet->entries->map(function (OrderSheetEntry $entry) use ($emptyQty) {
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
                    'db_id'           => $entry->id,
                    'order_id'        => $entry->order_id,
                    'customer_id'     => $entry->customer_id,
                    'customer_name'   => $entry->customer_name,
                    'customer_search' => $entry->customer_name,
                    'location'        => $entry->location ?? '',
                    'qty'             => $qty,
                    'extras'          => $extras,
                    'remarks'         => $entry->remarks ?? '',
                ];
            })->toArray();
        } else {
            // No saved sheet yet — seed from active subscriptions (sorted by name)
            $subscriptions = MealSubscription::with('customer')
                ->where('status', 'active')
                ->where('start_date', '<=', $this->sheetDate)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $this->sheetDate))
                ->get()
                ->filter(fn ($s) => $s->customer)
                ->unique('customer_id')
                ->sortBy(fn ($s) => $s->customer->name);

            $this->rows = $subscriptions->map(fn ($sub) => [
                'db_id'           => null,
                'order_id'        => null,
                'customer_id'     => $sub->customer_id,
                'customer_name'   => $sub->customer->name,
                'customer_search' => $sub->customer->name,
                'location'        => '',
                'qty'             => $emptyQty,
                'extras'          => [],
                'remarks'         => '',
            ])->values()->toArray();
        }

        // Merge daily-dish orders for this date that aren't already linked to a sheet entry
        $linkedOrderIds = collect($this->rows)->pluck('order_id')->filter()->all();

        $existingOrders = Order::with(['items.menuItem'])
            ->where('is_daily_dish', 1)
            ->whereDate('scheduled_date', $this->sheetDate)
            ->whereNotIn('status', ['Cancelled'])
            ->whereNotIn('id', $linkedOrderIds)
            ->orderBy('customer_name_snapshot')
            ->get();

        foreach ($existingOrders as $order) {
            $qty = $emptyQty;
            $extras = [];

            foreach ($order->items as $item) {
                if (! $item->menu_item_id) {
                    continue;
                }
                $colId = $menuItemToColumnId->get($item->menu_item_id);
                if ($colId) {
                    $qty[$colId] = (int) round($item->quantity);
                } else {
                    $extras[] = [
                        'menu_item_id'   => $item->menu_item_id,
                        'menu_item_name' => $item->menuItem?->name ?? $item->description_snapshot,
                        'quantity'       => (int) round($item->quantity),
                    ];
                }
            }

            $this->rows[] = [
                'db_id'           => null,
                'order_id'        => $order->id,
                'customer_id'     => $order->customer_id,
                'customer_name'   => $order->customer_name_snapshot,
                'customer_search' => $order->customer_name_snapshot,
                'location'        => '',
                'qty'             => $qty,
                'extras'          => $extras,
                'remarks'         => $order->notes ?? '',
            ];
        }

        // Always ensure a blank trailing row
        $this->ensureTrailingBlankRow();
    }

    private function ensureTrailingBlankRow(): void
    {
        $last = end($this->rows);
        if ($last === false || filled($last['customer_name'])) {
            $this->rows[] = $this->blankRow();
        }
    }

    public function updatedSheetDate(): void
    {
        $this->activeSearchRow = null;
        $this->customerSearchTerm = '';
        $this->activeExtraRow = null;
        $this->extraSearchTerm = '';
        $this->loadMenuItems();
        $this->loadRows();
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
    }

    public function removeRow(int $index): void
    {
        $dbId = $this->rows[$index]['db_id'] ?? null;
        if ($dbId) {
            OrderSheetEntry::destroy($dbId);
        }
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);

        if ($this->activeSearchRow === $index) {
            $this->activeSearchRow = null;
            $this->customerSearchTerm = '';
        }
    }

    public function clearRow(int $index): void
    {
        $qty = collect($this->menuItems)->mapWithKeys(fn ($item) => [$item['id'] => 0])->all();
        $this->rows[$index]['qty']    = $qty;
        $this->rows[$index]['extras'] = [];
        $this->rows[$index]['remarks'] = '';
    }

    public function bump(int $index, int $menuItemId, int $delta): void
    {
        $current = (int) ($this->rows[$index]['qty'][$menuItemId] ?? 0);
        $this->rows[$index]['qty'][$menuItemId] = max(0, $current + $delta);
    }

    // ── Customer search ──────────────────────────────────────

    public function focusCustomerSearch(int $rowIndex): void
    {
        $this->activeSearchRow    = $rowIndex;
        $this->customerSearchTerm = $this->rows[$rowIndex]['customer_search'] ?? '';
    }

    public function updatedCustomerSearchTerm(): void
    {
        unset($this->customerResults);
    }

    public function selectCustomer(int $customerId): void
    {
        if ($this->activeSearchRow === null) {
            return;
        }
        $customer = Customer::find($customerId);
        if (! $customer) {
            return;
        }
        $i = $this->activeSearchRow;
        $this->rows[$i]['customer_id']     = $customer->id;
        $this->rows[$i]['customer_name']   = $customer->name;
        $this->rows[$i]['customer_search'] = $customer->name;
        $this->activeSearchRow    = null;
        $this->customerSearchTerm = '';
    }

    public function clearCustomer(int $rowIndex): void
    {
        $this->rows[$rowIndex]['customer_id']     = null;
        $this->rows[$rowIndex]['customer_name']   = '';
        $this->rows[$rowIndex]['customer_search'] = '';
    }

    // ── Extra dish search ────────────────────────────────────

    public function focusExtraSearch(int $rowIndex): void
    {
        $this->activeExtraRow  = $rowIndex;
        $this->extraSearchTerm = '';
    }

    public function updatedExtraSearchTerm(): void
    {
        unset($this->extraResults);
    }

    public function selectExtra(int $menuItemId, string $name): void
    {
        if ($this->activeExtraRow === null) {
            return;
        }
        $i = $this->activeExtraRow;
        // Prevent duplicate
        foreach ($this->rows[$i]['extras'] as $e) {
            if ($e['menu_item_id'] === $menuItemId) {
                $this->activeExtraRow  = null;
                $this->extraSearchTerm = '';
                return;
            }
        }
        $this->rows[$i]['extras'][] = [
            'menu_item_id'   => $menuItemId,
            'menu_item_name' => $name,
            'quantity'       => 1,
        ];
        $this->activeExtraRow  = null;
        $this->extraSearchTerm = '';
    }

    public function removeExtra(int $rowIndex, int $extraIndex): void
    {
        unset($this->rows[$rowIndex]['extras'][$extraIndex]);
        $this->rows[$rowIndex]['extras'] = array_values($this->rows[$rowIndex]['extras']);
    }

    public function bumpExtra(int $rowIndex, int $extraIndex, int $delta): void
    {
        $current = (int) ($this->rows[$rowIndex]['extras'][$extraIndex]['quantity'] ?? 1);
        $this->rows[$rowIndex]['extras'][$extraIndex]['quantity'] = max(1, $current + $delta);
    }

    public function openDrawer(int $index): void
    {
        $this->compactDrawerRow = $index;
        $this->activeExtraRow   = null;
        $this->extraSearchTerm  = '';
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

    // ── Save ────────────────────────────────────────────────

    public function save(): void
    {
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

        $this->loadRows();
        $this->dispatch('toast', message: 'Sheet saved.', type: 'success');
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

        $this->dispatch('toast',
            message: $parts ? implode(', ', $parts) . '.' : 'All entries already up to date.',
            type: 'success'
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
    public function extraResults(): \Illuminate\Support\Collection
    {
        if ($this->activeExtraRow === null || strlen($this->extraSearchTerm) < 2) {
            return collect();
        }
        return MenuItem::query()
            ->search($this->extraSearchTerm)
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name']);
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

<div class="min-h-screen py-8 px-4 sm:px-8" style="background:#f5f3ee; font-family: Inter, ui-sans-serif, system-ui, sans-serif;"
     x-data="{
         openRow: null,
         isMobile: window.innerWidth < 768,
         init() {
             const onResize = () => { this.isMobile = window.innerWidth < 768; };
             window.addEventListener('resize', onResize);
             this.$el.addEventListener('remove', () => window.removeEventListener('resize', onResize));
         }
     }"
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
        /* Help Bot trigger is in our top bar — hide the floating one on this page */
        [x-data*="helpBotWidget"] > button:first-child { display: none !important; }
        @@media print {
            .no-print { display: none !important; }
            body { background: white; }
        }
    </style>

    <div class="max-w-[1320px] mx-auto">

        {{-- ── Top bar ── --}}
        <div class="no-print flex flex-wrap items-end justify-between gap-4 mb-5">
            <div>
                <div class="text-[11px] uppercase tracking-[0.2em] text-zinc-500 font-medium">Layla Kitchen</div>
                <h1 class="text-2xl font-semibold tracking-tight mt-0.5 text-zinc-900">Daily Order Sheet</h1>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
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
        <div x-show="!isMobile">
            <div class="ledger-paper rounded-sm relative">
                <div class="absolute top-0 right-0 w-20 h-20 overflow-hidden pointer-events-none" style="clip-path:polygon(100% 0,0 0,100% 100%);background:rgba(0,0,0,0.03)"></div>

                <div class="flex items-center justify-between px-6 pt-6 pb-3">
                    <div>
                        <div class="text-[10px] uppercase tracking-[0.25em] text-zinc-500">Order Sheet</div>
                        <div class="font-hand text-3xl text-red-700 leading-tight">
                            {{ \Carbon\Carbon::parse($sheetDate)->format('D, d M Y') }}
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-[10px] uppercase tracking-[0.25em] text-zinc-500">Total items</div>
                        <div class="font-hand text-3xl text-zinc-800">
                            {{ array_sum($this->dishTotals) + collect($this->extraTotals)->sum('qty') }}
                        </div>
                    </div>
                </div>

                <div class="px-6 pb-6 overflow-x-auto">
                    <table class="w-full border-collapse" style="min-width: 880px;">
                        <thead>
                            <tr>
                                <th class="border border-zinc-300 bg-white/60 align-bottom p-2 h-[120px] min-w-[220px]">
                                    <div class="text-left text-[11px] uppercase tracking-[0.15em] font-semibold text-zinc-600">Customer</div>
                                    <div class="text-right text-[10px] text-zinc-400 mt-1">
                                        {{ collect($rows)->filter(fn($r) => filled($r['customer_name']))->count() }}/{{ count($rows) }} filled
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
                                <tr wire:key="row-{{ $i }}" class="group">

                                    {{-- Customer --}}
                                    <td class="border border-zinc-300 px-3 py-2"
                                        x-data="{ dx: 0, dy: 0 }"
                                        x-on:focusin="const r = $el.getBoundingClientRect(); dx = r.left; dy = r.bottom + 2;">
                                        @if ($row['order_id'] ?? null)
                                            <div class="text-[9px] uppercase tracking-wider text-emerald-700 font-semibold mb-0.5 flex items-center gap-1">
                                                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                                Order #{{ $row['order_id'] }}
                                            </div>
                                        @endif
                                        <div class="flex items-center gap-1">
                                            <input value="{{ $row['customer_search'] }}"
                                                wire:change="$set('rows.{{ $i }}.customer_search', $event.target.value)"
                                                wire:focus="focusCustomerSearch({{ $i }})"
                                                wire:keyup="$set('customerSearchTerm', $event.target.value)"
                                                placeholder="Search customer…"
                                                autocomplete="off"
                                                class="flex-1 min-w-0 bg-transparent focus:outline-none font-hand text-[20px] text-blue-700 leading-none placeholder:text-zinc-300 placeholder:font-sans placeholder:text-[13px]" />
                                            @if ($row['customer_id'])
                                                <button wire:click="clearCustomer({{ $i }})" class="no-print text-zinc-300 hover:text-red-500 p-0.5">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                                </button>
                                            @endif
                                        </div>
                                        @if ($activeSearchRow === $i && count($this->customerResults) > 0)
                                            <div :style="`position:fixed; top:${dy}px; left:${dx}px; width:260px; z-index:9999;`"
                                                 class="bg-white border border-zinc-200 rounded-md shadow-lg overflow-hidden">
                                                <div class="max-h-52 overflow-y-auto">
                                                    @foreach ($this->customerResults as $customer)
                                                        <button type="button" wire:click="selectCustomer({{ $customer->id }})"
                                                            class="w-full px-3 py-2 text-left text-sm hover:bg-zinc-50">
                                                            <div class="font-medium text-zinc-900">{{ $customer->name }}</div>
                                                            @if ($customer->phone)
                                                                <div class="text-xs text-zinc-500">{{ $customer->phone }}</div>
                                                            @endif
                                                        </button>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    </td>

                                    {{-- Location --}}
                                    <td class="border border-zinc-300 px-2 py-2">
                                        <input value="{{ $row['location'] }}"
                                            wire:change="$set('rows.{{ $i }}.location', $event.target.value)"
                                            placeholder="—"
                                            class="w-full bg-transparent focus:outline-none text-[13px] text-zinc-700 text-center" />
                                    </td>

                                    {{-- Dish quantities --}}
                                    @foreach ($menuItems as $item)
                                        <td class="border border-zinc-300 px-1 py-2 text-center">
                                            <div class="inline-flex items-center gap-0.5 py-0.5">
                                                <button class="stepper-btn no-print"
                                                    wire:click="bump({{ $i }}, {{ $item['id'] }}, -1)"
                                                    @disabled(($row['qty'][$item['id']] ?? 0) === 0)>
                                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/></svg>
                                                </button>
                                                <span class="inline-block w-5 text-center font-semibold tabular-nums text-[13px]
                                                    {{ ($row['qty'][$item['id']] ?? 0) === 0 ? 'text-zinc-300' : 'text-zinc-900' }}">
                                                    {{ $row['qty'][$item['id']] ?? 0 }}
                                                </span>
                                                <button class="stepper-btn no-print"
                                                    wire:click="bump({{ $i }}, {{ $item['id'] }}, 1)">
                                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                                </button>
                                            </div>
                                        </td>
                                    @endforeach

                                    {{-- Extras --}}
                                    <td class="border border-zinc-300 px-2 py-2"
                                        x-data="{ dx: 0, dy: 0 }">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            @foreach ($row['extras'] as $ei => $extra)
                                                <div class="inline-flex items-center gap-1 bg-amber-50 border border-amber-200 rounded-md pl-2 pr-1 py-0.5">
                                                    <span class="text-[12px] text-amber-900 font-medium">{{ $extra['menu_item_name'] }}</span>
                                                    <span class="text-zinc-300 text-[11px]">×</span>
                                                    <div class="inline-flex items-center gap-0.5">
                                                        <button class="stepper-btn no-print" style="width:16px;height:16px"
                                                            wire:click="bumpExtra({{ $i }}, {{ $ei }}, -1)"
                                                            @disabled($extra['quantity'] <= 1)>
                                                            <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/></svg>
                                                        </button>
                                                        <span class="text-[12px] font-semibold tabular-nums w-4 text-center">{{ $extra['quantity'] }}</span>
                                                        <button class="stepper-btn no-print" style="width:16px;height:16px"
                                                            wire:click="bumpExtra({{ $i }}, {{ $ei }}, 1)">
                                                            <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                                        </button>
                                                    </div>
                                                    <button wire:click="removeExtra({{ $i }}, {{ $ei }})"
                                                        class="no-print text-zinc-400 hover:text-red-600 p-0.5" title="Remove">
                                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                                    </button>
                                                </div>
                                            @endforeach
                                            <button
                                                x-on:click="const r = $el.getBoundingClientRect(); dx = r.left; dy = r.bottom + 4;"
                                                wire:click="focusExtraSearch({{ $i }})"
                                                class="no-print inline-flex items-center gap-0.5 px-1.5 py-0.5 text-[11px] text-zinc-500 hover:text-zinc-900 border border-dashed border-zinc-300 hover:border-zinc-500 rounded-md">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                                Add dish
                                            </button>
                                        </div>

                                        @if ($activeExtraRow === $i)
                                            <div :style="`position:fixed; top:${dy}px; left:${dx}px; width:260px; z-index:9999;`"
                                                 class="bg-white border border-zinc-200 rounded-md shadow-lg p-2">
                                                <input wire:model.live.debounce.250ms="extraSearchTerm"
                                                    placeholder="Search menu items…"
                                                    class="w-full px-2 py-1.5 text-[13px] border border-zinc-200 rounded focus:outline-none focus:border-zinc-900"
                                                    autofocus />
                                                @if (count($this->extraResults) > 0)
                                                    <div class="mt-1 max-h-48 overflow-y-auto">
                                                        @foreach ($this->extraResults as $mi)
                                                            <button type="button"
                                                                wire:click="selectExtra({{ $mi->id }}, '{{ addslashes($mi->name) }}')"
                                                                class="w-full px-2 py-1.5 text-left text-[13px] hover:bg-zinc-50 rounded">
                                                                {{ $mi->name }}
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                @elseif (strlen($extraSearchTerm) >= 2)
                                                    <div class="py-2 text-[12px] text-zinc-500 text-center">No items found</div>
                                                @endif
                                                <button wire:click="$set('activeExtraRow', null)"
                                                    class="mt-1 w-full text-[11px] text-zinc-400 hover:text-zinc-600 py-1">Cancel</button>
                                            </div>
                                        @endif
                                    </td>

                                    {{-- Remarks --}}
                                    <td class="border border-zinc-300 px-2 py-2">
                                        <input value="{{ $row['remarks'] }}"
                                            wire:change="$set('rows.{{ $i }}.remarks', $event.target.value)"
                                            placeholder="—"
                                            class="w-full bg-transparent focus:outline-none text-[12px] text-zinc-600 font-hand" />
                                    </td>

                                    {{-- Row actions --}}
                                    <td class="no-print px-1 text-center">
                                        <div class="flex flex-col items-center gap-0.5 opacity-0 group-hover:opacity-100 transition">
                                            <button wire:click="clearRow({{ $i }})" title="Clear row"
                                                class="p-1 rounded hover:bg-amber-50 text-zinc-400 hover:text-amber-700">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2M19 6l-.867 12.142A2 2 0 0116.138 20H7.862a2 2 0 01-1.995-1.858L5 6"/></svg>
                                            </button>
                                            <button wire:click="removeRow({{ $i }})" title="Delete row"
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
                                        @if (($this->dishTotals[$item['id']] ?? 0) > 0)
                                            <span class="font-hand text-[22px] text-red-700">{{ $this->dishTotals[$item['id']] }}</span>
                                        @else
                                            <span class="text-zinc-300">—</span>
                                        @endif
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
                                    <span class="font-hand text-[22px] text-red-700">
                                        {{ array_sum($this->dishTotals) + collect($this->extraTotals)->sum('qty') ?: '—' }}
                                    </span>
                                </td>
                                <td class="no-print"></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="no-print mt-3">
                        <button wire:click="addRow"
                            class="flex items-center gap-2 px-3 py-2 text-[13px] font-medium text-zinc-700 hover:text-zinc-900 border border-dashed border-zinc-300 hover:border-zinc-500 rounded-lg transition">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                            Add row
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════
             MOBILE VIEW (<md)
             ══════════════════════════════════════════════════ --}}
        <div x-show="isMobile" style="padding-bottom: 120px;">

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
                            <span class="text-[12px] font-bold tabular-nums {{ $t === 0 ? 'text-zinc-300' : 'text-red-700' }}">{{ $t }}</span>
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
                        $sum = array_sum($row['qty']) + collect($row['extras'])->sum('quantity');
                    @endphp
                    <div wire:key="mobile-row-{{ $i }}" class="bg-white rounded-xl border border-zinc-200 overflow-hidden">

                        {{-- Card header (always visible) --}}
                        <div class="flex items-center gap-2 px-3 py-2.5">
                            <button x-on:click="openRow = (openRow === {{ $i }}) ? null : {{ $i }}"
                                class="w-8 h-8 rounded-full bg-zinc-100 flex items-center justify-center flex-shrink-0">
                                <span class="text-[13px] font-semibold text-zinc-600">
                                    {{ strtoupper(substr($row['customer_name'] ?: '?', 0, 1)) }}
                                </span>
                            </button>

                            {{-- Customer name input (always editable inline) --}}
                            <div class="flex-1 min-w-0 relative">
                                <input value="{{ $row['customer_search'] }}"
                                    wire:change="$set('rows.{{ $i }}.customer_search', $event.target.value)"
                                    wire:focus="focusCustomerSearch({{ $i }})"
                                    wire:keyup="$set('customerSearchTerm', $event.target.value)"
                                    placeholder="Enter customer…"
                                    autocomplete="off"
                                    class="w-full font-semibold text-[15px] bg-transparent focus:outline-none placeholder:text-zinc-300 placeholder:font-normal" />

                                @if ($activeSearchRow === $i && count($this->customerResults) > 0)
                                    <div class="absolute left-0 top-full z-20 mt-0.5 w-[260px] bg-white border border-zinc-200 rounded-md shadow-lg overflow-hidden">
                                        <div class="max-h-52 overflow-y-auto">
                                            @foreach ($this->customerResults as $customer)
                                                <button type="button" wire:click="selectCustomer({{ $customer->id }})"
                                                    class="w-full px-3 py-2 text-left text-sm hover:bg-zinc-50">
                                                    <div class="font-medium text-zinc-900">{{ $customer->name }}</div>
                                                    @if ($customer->phone)
                                                        <div class="text-xs text-zinc-500">{{ $customer->phone }}</div>
                                                    @endif
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>

                            {{-- Qty badges --}}
                            <div class="flex items-center gap-0.5 flex-shrink-0">
                                @foreach ($menuItems as $idx => $item)
                                    @if (($row['qty'][$item['id']] ?? 0) > 0)
                                        <span class="text-[10px] font-bold tabular-nums w-5 h-5 rounded flex items-center justify-center bg-red-600 text-white">
                                            {{ $row['qty'][$item['id']] }}
                                        </span>
                                    @endif
                                @endforeach
                                @if (collect($row['extras'])->sum('quantity') > 0)
                                    <span class="text-[10px] font-bold tabular-nums w-5 h-5 rounded flex items-center justify-center bg-amber-500 text-white">
                                        +{{ collect($row['extras'])->sum('quantity') }}
                                    </span>
                                @endif
                                @if ($sum === 0)
                                    <span class="text-[11px] text-zinc-300">—</span>
                                @endif
                            </div>

                            <button x-on:click="openRow = (openRow === {{ $i }}) ? null : {{ $i }}" class="p-1">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                     class="text-zinc-400 transition-transform" :class="openRow === {{ $i }} ? 'rotate-90' : ''">
                                    <path d="M9 5l7 7-7 7"/>
                                </svg>
                            </button>
                        </div>

                        {{-- Card body (expanded) --}}
                        <div x-show="openRow === {{ $i }}" x-collapse
                             class="border-t border-zinc-100 px-3.5 py-3 bg-zinc-50/50">

                            {{-- Location --}}
                            <div class="mb-3">
                                <div class="text-[10px] uppercase tracking-wider text-zinc-500 font-semibold mb-1">Location</div>
                                <input value="{{ $row['location'] }}"
                                    wire:change="$set('rows.{{ $i }}.location', $event.target.value)"
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
                                                <button wire:click="bump({{ $i }}, {{ $item['id'] }}, -1)"
                                                    class="mobile-stepper-btn" @disabled(($row['qty'][$item['id']] ?? 0) === 0)>
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/></svg>
                                                </button>
                                                <span class="w-6 text-center text-[17px] font-semibold tabular-nums {{ ($row['qty'][$item['id']] ?? 0) === 0 ? 'text-zinc-300' : 'text-red-700' }}">
                                                    {{ $row['qty'][$item['id']] ?? 0 }}
                                                </span>
                                                <button wire:click="bump({{ $i }}, {{ $item['id'] }}, 1)"
                                                    class="mobile-stepper-btn inc">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
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
                                                <button wire:click="bumpExtra({{ $i }}, {{ $ei }}, -1)"
                                                    class="mobile-stepper-btn" style="width:28px;height:28px" @disabled($extra['quantity'] <= 1)>
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/></svg>
                                                </button>
                                                <span class="w-5 text-center text-[14px] font-semibold tabular-nums text-amber-800">{{ $extra['quantity'] }}</span>
                                                <button wire:click="bumpExtra({{ $i }}, {{ $ei }}, 1)"
                                                    class="mobile-stepper-btn inc" style="width:28px;height:28px">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                                </button>
                                            </div>
                                            <button wire:click="removeExtra({{ $i }}, {{ $ei }})" class="text-zinc-400 active:text-red-600 p-1">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                    @endforeach

                                    <button wire:click="focusExtraSearch({{ $i }})"
                                        class="w-full flex items-center justify-center gap-1 py-2 text-[12px] font-medium text-amber-800 border border-dashed border-amber-300 rounded-lg bg-amber-50/40">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                        Add other dish
                                    </button>

                                    @if ($activeExtraRow === $i)
                                        <div class="mt-1 p-2 bg-white border border-zinc-200 rounded-lg">
                                            <input wire:model.live.debounce.250ms="extraSearchTerm"
                                                placeholder="Search menu items…"
                                                class="w-full px-2 py-1.5 text-[13px] border border-zinc-200 rounded focus:outline-none"
                                                autofocus />
                                            @if (count($this->extraResults) > 0)
                                                <div class="mt-1 max-h-40 overflow-y-auto">
                                                    @foreach ($this->extraResults as $mi)
                                                        <button type="button"
                                                            wire:click="selectExtra({{ $mi->id }}, '{{ addslashes($mi->name) }}')"
                                                            class="w-full px-2 py-1.5 text-left text-[13px] hover:bg-zinc-50 rounded">
                                                            {{ $mi->name }}
                                                        </button>
                                                    @endforeach
                                                </div>
                                            @endif
                                            <button wire:click="$set('activeExtraRow', null)"
                                                class="mt-1 w-full text-[11px] text-zinc-400 py-1">Cancel</button>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            {{-- Remarks --}}
                            <div class="mb-3">
                                <div class="text-[10px] uppercase tracking-wider text-zinc-500 font-semibold mb-1">Remarks</div>
                                <input value="{{ $row['remarks'] }}"
                                    wire:change="$set('rows.{{ $i }}.remarks', $event.target.value)"
                                    placeholder="—"
                                    class="w-full px-2.5 py-1.5 text-[13px] bg-white border border-zinc-200 rounded-md focus:outline-none focus:border-zinc-900" />
                            </div>

                            {{-- Row actions --}}
                            <div class="pt-3 border-t border-zinc-200/70 flex items-center justify-between">
                                <span class="text-[11px] text-zinc-500">{{ $sum }} item{{ $sum === 1 ? '' : 's' }}</span>
                                <div class="flex items-center gap-1">
                                    <button wire:click="clearRow({{ $i }})"
                                        class="flex items-center gap-1 text-[12px] text-amber-700 px-2 py-1 rounded-md active:bg-amber-50 {{ $sum === 0 ? 'opacity-30' : '' }}">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2M19 6l-.867 12.142A2 2 0 0116.138 20H7.862a2 2 0 01-1.995-1.858L5 6"/></svg>
                                        Clear
                                    </button>
                                    <button wire:click="removeRow({{ $i }})"
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

            <button wire:click="addRow"
                class="mt-3 w-full flex items-center justify-center gap-2 px-3 py-3 text-[13px] font-medium text-zinc-700 bg-white border border-dashed border-zinc-300 rounded-xl active:bg-zinc-50">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Add person
            </button>

            @else
            {{-- ── Compact tap-grid view ── --}}
            <div class="overflow-x-auto -mx-1 px-1">
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
                            <tr wire:key="grid-row-{{ $i }}" class="border-t border-zinc-200/60">
                                <td class="px-1 py-1.5">
                                    <input value="{{ $row['customer_search'] }}"
                                        wire:change="$set('rows.{{ $i }}.customer_search', $event.target.value)"
                                        wire:focus="focusCustomerSearch({{ $i }})"
                                        wire:keyup="$set('customerSearchTerm', $event.target.value)"
                                        placeholder="Name"
                                        autocomplete="off"
                                        class="w-full text-[12px] font-semibold bg-transparent focus:outline-none focus:bg-white rounded px-1 py-0.5 placeholder:text-zinc-300 placeholder:font-normal" />
                                    @if ($activeSearchRow === $i && count($this->customerResults) > 0)
                                        <div class="absolute left-0 z-20 mt-0.5 w-[220px] bg-white border border-zinc-200 rounded-md shadow-lg overflow-hidden">
                                            @foreach ($this->customerResults as $customer)
                                                <button type="button" wire:click="selectCustomer({{ $customer->id }})"
                                                    class="w-full px-3 py-2 text-left text-sm hover:bg-zinc-50">
                                                    <div class="font-medium text-zinc-900 text-[12px]">{{ $customer->name }}</div>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                @foreach ($menuItems as $item)
                                    @php $q = $row['qty'][$item['id']] ?? 0; $isMain = in_array($item['role'], ['main','diet','vegetarian']); $color = $isMain ? '#dc2626' : '#059669'; @endphp
                                    <td class="px-0.5 py-1 text-center">
                                        <button wire:click="bump({{ $i }}, {{ $item['id'] }}, 1)"
                                            class="os-grid-btn {{ $q === 0 ? 'empty' : 'filled' }}"
                                            style="{{ $q > 0 ? "background-color:{$color};" : '' }}">
                                            {{ $q === 0 ? '+' : $q }}
                                        </button>
                                        @if ($q > 0)
                                            <button wire:click="bump({{ $i }}, {{ $item['id'] }}, -1)"
                                                style="display:block;width:100%;margin-top:2px;font-size:11px;font-weight:700;color:{{ $color }};background:none;border:none;cursor:pointer;line-height:1;padding:1px 0;">
                                                −
                                            </button>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-0.5 py-1 text-center">
                                    @php $extraSum = collect($row['extras'])->sum('quantity'); @endphp
                                    <button wire:click="openDrawer({{ $i }})"
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
                                <td class="px-0.5 py-2 text-center font-hand text-[18px] {{ $t === 0 ? 'text-zinc-300' : (in_array($item['role'],['salad','dessert']) ? 'text-emerald-700' : 'text-red-700') }}">
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

            <button wire:click="addRow"
                class="mt-3 w-full flex items-center justify-center gap-2 px-3 py-2.5 text-[13px] font-medium text-zinc-700 bg-white border border-dashed border-zinc-300 rounded-xl active:bg-zinc-50">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Add person
            </button>
            <div class="mt-1.5 text-[10px] text-zinc-400 text-center">Tap = +1 · hold = −1 · "…" for extras / notes</div>

            {{-- Compact drawer --}}
            @if ($compactDrawerRow !== null && isset($rows[$compactDrawerRow]))
                @php $dr = $rows[$compactDrawerRow]; $di = $compactDrawerRow; @endphp
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
                                    <button wire:click="bumpExtra({{ $di }}, {{ $ei }}, -1)" class="mobile-stepper-btn" style="width:28px;height:28px" @disabled($extra['quantity']<=1)>
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/></svg>
                                    </button>
                                    <span class="w-5 text-center text-[13px] font-semibold tabular-nums">{{ $extra['quantity'] }}</span>
                                    <button wire:click="bumpExtra({{ $di }}, {{ $ei }}, 1)" class="mobile-stepper-btn inc" style="width:28px;height:28px">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                    </button>
                                    <button wire:click="removeExtra({{ $di }}, {{ $ei }})" class="text-zinc-400 p-1">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            @endforeach
                            <button wire:click="focusExtraSearch({{ $di }})"
                                class="w-full flex items-center justify-center gap-1 py-2 text-[12px] font-medium text-amber-800 border border-dashed border-amber-300 rounded-lg bg-amber-50/40">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                                Add other dish
                            </button>
                            @if ($activeExtraRow === $di)
                                <div class="p-2 bg-zinc-50 border border-zinc-200 rounded-lg mt-1">
                                    <input wire:model.live.debounce.250ms="extraSearchTerm"
                                        placeholder="Search menu items…"
                                        class="w-full px-2 py-1.5 text-[13px] border border-zinc-200 rounded focus:outline-none" autofocus />
                                    @foreach ($this->extraResults as $mi)
                                        <button type="button" wire:click="selectExtra({{ $mi->id }}, '{{ addslashes($mi->name) }}')"
                                            class="w-full px-2 py-1.5 text-left text-[13px] hover:bg-zinc-50 rounded">{{ $mi->name }}</button>
                                    @endforeach
                                    <button wire:click="$set('activeExtraRow', null)" class="mt-1 w-full text-[11px] text-zinc-400 py-1">Cancel</button>
                                </div>
                            @endif
                        </div>

                        {{-- Remarks --}}
                        <div class="mb-4">
                            <div class="text-[10px] uppercase tracking-wider text-zinc-500 font-semibold mb-1">Remarks</div>
                            <input value="{{ $dr['remarks'] }}"
                                wire:change="$set('rows.{{ $di }}.remarks', $event.target.value)"
                                placeholder="—"
                                class="w-full px-2.5 py-1.5 text-[13px] bg-white border border-zinc-200 rounded-md focus:outline-none" />
                        </div>

                        {{-- Actions --}}
                        <div class="grid grid-cols-2 gap-2">
                            <button wire:click="clearRow({{ $di }}); closeDrawer()"
                                class="flex items-center justify-center gap-1 py-2.5 text-[13px] font-semibold text-amber-700 bg-amber-50 border border-amber-200 rounded-lg">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2M19 6l-.867 12.142A2 2 0 0116.138 20H7.862a2 2 0 01-1.995-1.858L5 6"/></svg>
                                Clear row
                            </button>
                            <button wire:click="removeRow({{ $di }}); closeDrawer()"
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
                            {{ array_sum($this->dishTotals) + collect($this->extraTotals)->sum('qty') }} items
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
    </div>

    {{-- Print / PDF export --}}
    <script>
    function exportPDF() {
        const win = window.open('', '_blank');
        if (!win) { alert('Allow pop-ups to export PDF.'); return; }

        const date = document.querySelector('input[type=date]')?.value || '';
        const prettyDate = date ? new Date(date + 'T00:00:00').toLocaleDateString('en-GB', {weekday:'long',day:'2-digit',month:'long',year:'numeric'}) : '';

        const table = document.querySelector('.ledger-paper table');
        const printTable = table ? table.outerHTML
            .replace(/class="[^"]*no-print[^"]*"/g, 'style="display:none"')
            : '<p>No data</p>';

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
        <script>setTimeout(()=>window.print(),300);<\/script>
        </body></html>`);
        win.document.close();
    }
    </script>
</div>

<?php

use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\PastryOrder;
use App\Models\PastryOrderImage;
use App\Services\PastryOrders\PastryOrderCreateService;
use App\Services\PastryOrders\PastryOrderImageService;
use App\Services\PastryOrders\PastryOrderUpdateService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;
    use WithFileUploads;

    // ── Filters ────────────────────────────────────────────────────────────────
    public string  $status         = 'all';
    public ?string $type           = null;
    public ?int    $branch_id      = null;
    public ?string $scheduled_date = null;
    public string  $search         = '';

    // ── Create drawer ──────────────────────────────────────────────────────────
    public bool    $showCreateDrawer       = false;
    public int     $c_branch_id            = 1;
    public string  $c_status               = 'Draft';
    public string  $c_type                 = 'Pickup';
    public ?int    $c_customer_id          = null;
    public string  $c_customer_search      = '';
    public ?string $c_customer_name        = null;
    public ?string $c_customer_phone       = null;
    public ?string $c_delivery_address     = null;
    public ?string $c_scheduled_date       = null;
    public ?string $c_scheduled_time       = null;
    public ?string $c_notes                = null;
    public ?string $c_sales_order_number   = null;
    public float   $c_order_discount       = 0.0;
    public array   $c_items                = [];
    public array   $c_item_search          = [];
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile[] */
    public $c_images = [];

    // ── Edit drawer ────────────────────────────────────────────────────────────
    public bool    $showEditDrawer          = false;
    public ?int    $e_id                    = null;
    public string  $e_order_number          = '';
    public int     $e_branch_id             = 1;
    public string  $e_status                = 'Draft';
    public string  $e_type                  = 'Pickup';
    public ?int    $e_customer_id           = null;
    public string  $e_customer_search       = '';
    public ?string $e_customer_name         = null;
    public ?string $e_customer_phone        = null;
    public ?string $e_delivery_address      = null;
    public ?string $e_scheduled_date        = null;
    public ?string $e_scheduled_time        = null;
    public ?string $e_notes                 = null;
    public ?string $e_sales_order_number    = null;
    public float   $e_order_discount        = 0.0;
    public array   $e_items             = [];
    public array   $e_item_search       = [];
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile[] */
    public $e_new_images       = [];
    public array $e_existing_images  = [];
    public array $e_remove_image_ids = [];
    public bool  $e_is_invoiced      = false;

    // ── View drawer ────────────────────────────────────────────────────────────
    public bool    $showViewDrawer          = false;
    public ?int    $v_order_id              = null;
    public string  $v_order_number          = '';
    public ?string $v_sales_order_number    = null;
    public string  $v_customer_name         = '';
    public ?string $v_customer_phone        = null;
    public string  $v_status                = '';
    public ?string $v_scheduled_date        = null;
    public string  $v_type           = '';
    public ?string $v_notes          = null;
    public array   $v_items          = [];
    public array   $v_images         = [];

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->c_scheduled_date = now()->toDateString();
    }

    public function updating(string $field): void
    {
        if (in_array($field, ['status', 'type', 'branch_id', 'scheduled_date', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $orders = $this->buildQuery()->paginate(15);

        $createCustomers = collect();
        if ($this->showCreateDrawer && $this->c_customer_id === null && $this->c_customer_search !== '' && Schema::hasTable('customers')) {
            $createCustomers = Customer::query()->active()->search($this->c_customer_search)->orderBy('name')->limit(25)->get();
        }

        $editCustomers = collect();
        if ($this->showEditDrawer && $this->e_customer_id === null && $this->e_customer_search !== '' && Schema::hasTable('customers')) {
            $editCustomers = Customer::query()->active()->search($this->e_customer_search)->orderBy('name')->limit(25)->get();
        }

        $branches = Schema::hasTable('branches')
            ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get()
            : collect();

        return compact('orders', 'branches', 'createCustomers', 'editCustomers');
    }

    // ── Create drawer actions ──────────────────────────────────────────────────

    public function openCreateDrawer(): void
    {
        $this->c_branch_id            = 1;
        $this->c_status               = 'Draft';
        $this->c_type                 = 'Pickup';
        $this->c_customer_id          = null;
        $this->c_customer_search      = '';
        $this->c_customer_name        = null;
        $this->c_customer_phone       = null;
        $this->c_delivery_address     = null;
        $this->c_scheduled_date       = now()->toDateString();
        $this->c_scheduled_time       = null;
        $this->c_notes                = null;
        $this->c_sales_order_number   = null;
        $this->c_order_discount       = 0.0;
        $this->c_items                = [];
        $this->c_item_search          = [];
        $this->c_images               = [];
        $this->addCreateItem();
        $this->resetValidation();
        $this->showCreateDrawer = true;
    }

    public function closeCreateDrawer(): void
    {
        $this->showCreateDrawer = false;
        $this->resetValidation();
    }

    public function addCreateItem(): void
    {
        $this->c_items[]       = ['menu_item_id' => null, 'quantity' => 1, 'discount_amount' => 0, 'sort_order' => count($this->c_items)];
        $this->c_item_search[] = '';
    }

    public function removeCreateItem(int $i): void
    {
        unset($this->c_items[$i], $this->c_item_search[$i]);
        $this->c_items       = array_values($this->c_items);
        $this->c_item_search = array_values($this->c_item_search);
    }

    public function selectCreateMenuItem(int $i, int $menuItemId, string $label): void
    {
        if (! array_key_exists($i, $this->c_items)) return;
        $this->c_items[$i]['menu_item_id']    = $menuItemId;
        $this->c_items[$i]['quantity']        ??= 1;
        $this->c_items[$i]['discount_amount'] ??= 0;
        $this->c_items[$i]['sort_order']      ??= $i;
        $this->c_item_search[$i]              = $label;
        foreach ($this->c_items as $row) { if (empty($row['menu_item_id'])) return; }
        $this->addCreateItem();
    }

    public function clearCreateMenuItemSearch(int $i): void
    {
        if (! array_key_exists($i, $this->c_items)) return;
        $this->c_items[$i]['menu_item_id'] = null;
        $this->c_item_search[$i]           = '';
    }

    public function updatedCCustomerSearch(): void
    {
        if ($this->c_customer_id !== null) {
            $this->c_customer_id    = null;
            $this->c_customer_name  = null;
            $this->c_customer_phone = null;
        }
    }

    public function selectCreateCustomer(int $id): void
    {
        $c = Customer::find($id);
        if (! $c) return;
        $this->c_customer_id     = $id;
        $this->c_customer_name   = $c->name;
        $this->c_customer_phone  = $c->phone ?? null;
        $this->c_customer_search = trim($c->name.' '.($c->phone ?? ''));
    }

    public function saveCreate(PastryOrderCreateService $service): void
    {
        $this->authorize('pastry-orders.manage');

        $items = collect($this->c_items)
            ->filter(fn ($r) => ! empty($r['menu_item_id']))
            ->values()
            ->toArray();

        try {
            $validated = validator([
                'branch_id'                 => $this->c_branch_id,
                'status'                    => $this->c_status,
                'type'                      => $this->c_type,
                'customer_id'               => $this->c_customer_id,
                'customer_name_snapshot'    => $this->c_customer_name ?? trim($this->c_customer_search),
                'customer_phone_snapshot'   => $this->c_customer_phone,
                'delivery_address_snapshot' => $this->c_delivery_address,
                'scheduled_date'            => $this->c_scheduled_date,
                'scheduled_time'            => $this->c_scheduled_time,
                'notes'                     => $this->c_notes,
                'sales_order_number'        => $this->c_sales_order_number,
                'order_discount_amount'     => $this->c_order_discount,
                'items'                     => $items,
            ], [
                'branch_id'                 => ['required', 'integer'],
                'status'                    => ['required', 'string', 'in:Draft,Confirmed,InProduction,Ready,Delivered,Cancelled'],
                'type'                      => ['required', 'string', 'in:Pickup,Delivery'],
                'customer_id'               => ['nullable', 'integer'],
                'customer_name_snapshot'    => ['required', 'string', 'max:255'],
                'customer_phone_snapshot'   => ['nullable', 'string', 'max:50'],
                'delivery_address_snapshot' => ['nullable', 'string'],
                'scheduled_date'            => ['nullable', 'date'],
                'scheduled_time'            => ['nullable'],
                'notes'                     => ['nullable', 'string'],
                'sales_order_number'        => ['nullable', 'string', 'max:100'],
                'order_discount_amount'     => ['nullable', 'numeric', 'min:0'],
                'items'                     => ['required', 'array', 'min:1'],
                'items.*.menu_item_id'      => ['required', 'integer'],
                'items.*.quantity'          => ['required', 'numeric', 'min:0.001'],
                'items.*.discount_amount'   => ['nullable', 'numeric', 'min:0'],
            ])->validate();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
            return;
        }

        $uploadedImages = collect($this->c_images)
            ->filter(fn ($f) => $f instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
            ->values()->all();

        try {
            $service->create($validated, $uploadedImages, Auth::id());
            session()->flash('status_message', __('Pastry order created.'));
            $this->closeCreateDrawer();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $msgs) {
                foreach ($msgs as $m) { $this->addError($field, $m); }
            }
        }
    }

    // ── Edit drawer actions ────────────────────────────────────────────────────

    public function openEditDrawer(int $orderId, PastryOrderImageService $imageService): void
    {
        $order = PastryOrder::with(['items', 'images'])->find($orderId);
        if (! $order) return;

        $this->e_id                   = $order->id;
        $this->e_order_number         = $order->order_number;
        $this->e_branch_id            = (int) ($order->branch_id ?? 1);
        $this->e_status               = $order->status;
        $this->e_type                 = $order->type;
        $this->e_customer_id          = $order->customer_id;
        $this->e_customer_name        = $order->customer_name_snapshot;
        $this->e_customer_phone       = $order->customer_phone_snapshot;
        $this->e_delivery_address     = $order->delivery_address_snapshot;
        $this->e_scheduled_date       = $order->scheduled_date?->format('Y-m-d');
        $this->e_scheduled_time       = $order->scheduled_time ?? null;
        $this->e_notes                = $order->notes;
        $this->e_sales_order_number   = $order->sales_order_number;
        $this->e_order_discount       = (float) $order->order_discount_amount;
        $this->e_is_invoiced      = $order->isInvoiced();
        $this->e_new_images       = [];
        $this->e_remove_image_ids = [];
        $this->e_customer_search  = $order->customer_id
            ? trim(($order->customer_name_snapshot ?? '').' '.($order->customer_phone_snapshot ?? ''))
            : '';

        $this->e_existing_images = $imageService->presignedUrlsForOrder($order);

        $this->e_items       = [];
        $this->e_item_search = [];
        foreach ($order->items->sortBy('sort_order') as $item) {
            $this->e_items[]       = ['menu_item_id' => $item->menu_item_id, 'quantity' => (float) $item->quantity, 'discount_amount' => (float) $item->discount_amount, 'sort_order' => $item->sort_order];
            $this->e_item_search[] = $item->description_snapshot;
        }
        $this->addEditItem();
        $this->resetValidation();
        $this->showEditDrawer = true;
    }

    public function closeEditDrawer(): void
    {
        $this->showEditDrawer = false;
        $this->e_id           = null;
        $this->resetValidation();
    }

    public function addEditItem(): void
    {
        $this->e_items[]       = ['menu_item_id' => null, 'quantity' => 1, 'discount_amount' => 0, 'sort_order' => count($this->e_items)];
        $this->e_item_search[] = '';
    }

    public function removeEditItem(int $i): void
    {
        unset($this->e_items[$i], $this->e_item_search[$i]);
        $this->e_items       = array_values($this->e_items);
        $this->e_item_search = array_values($this->e_item_search);
    }

    public function selectEditMenuItem(int $i, int $menuItemId, string $label): void
    {
        if (! array_key_exists($i, $this->e_items)) return;
        $this->e_items[$i]['menu_item_id']    = $menuItemId;
        $this->e_items[$i]['quantity']        ??= 1;
        $this->e_items[$i]['discount_amount'] ??= 0;
        $this->e_items[$i]['sort_order']      ??= $i;
        $this->e_item_search[$i]              = $label;
        foreach ($this->e_items as $row) { if (empty($row['menu_item_id'])) return; }
        $this->addEditItem();
    }

    public function clearEditMenuItemSearch(int $i): void
    {
        if (! array_key_exists($i, $this->e_items)) return;
        $this->e_items[$i]['menu_item_id'] = null;
        $this->e_item_search[$i]           = '';
    }

    public function markImageForRemoval(int $imageId): void
    {
        if (! in_array($imageId, $this->e_remove_image_ids, true)) {
            $this->e_remove_image_ids[] = $imageId;
        }
    }

    public function undoImageRemoval(int $imageId): void
    {
        $this->e_remove_image_ids = array_values(
            array_filter($this->e_remove_image_ids, fn ($id) => $id !== $imageId)
        );
    }

    public function updatedECustomerSearch(): void
    {
        if ($this->e_customer_id !== null) {
            $this->e_customer_id    = null;
            $this->e_customer_name  = null;
            $this->e_customer_phone = null;
        }
    }

    public function selectEditCustomer(int $id): void
    {
        $c = Customer::find($id);
        if (! $c) return;
        $this->e_customer_id     = $id;
        $this->e_customer_name   = $c->name;
        $this->e_customer_phone  = $c->phone ?? null;
        $this->e_customer_search = trim($c->name.' '.($c->phone ?? ''));
    }

    public function saveEdit(PastryOrderUpdateService $service): void
    {
        $this->authorize('pastry-orders.manage');

        if (! $this->e_id) return;
        $order = PastryOrder::find($this->e_id);
        if (! $order) {
            session()->flash('error_message', __('Order not found.'));
            $this->closeEditDrawer();
            return;
        }

        $items = collect($this->e_items)
            ->filter(fn ($r) => ! empty($r['menu_item_id']))
            ->values()
            ->toArray();

        try {
            $validated = validator([
                'branch_id'                 => $this->e_branch_id,
                'status'                    => $this->e_status,
                'type'                      => $this->e_type,
                'customer_id'               => $this->e_customer_id,
                'customer_name_snapshot'    => $this->e_customer_name ?? trim($this->e_customer_search),
                'customer_phone_snapshot'   => $this->e_customer_phone,
                'delivery_address_snapshot' => $this->e_delivery_address,
                'scheduled_date'            => $this->e_scheduled_date,
                'scheduled_time'            => $this->e_scheduled_time,
                'notes'                     => $this->e_notes,
                'sales_order_number'        => $this->e_sales_order_number,
                'order_discount_amount'     => $this->e_order_discount,
                'items'                     => $items,
            ], [
                'branch_id'                 => ['required', 'integer'],
                'status'                    => ['required', 'string', 'in:Draft,Confirmed,InProduction,Ready,Delivered,Cancelled'],
                'type'                      => ['required', 'string', 'in:Pickup,Delivery'],
                'customer_id'               => ['nullable', 'integer'],
                'customer_name_snapshot'    => ['required', 'string', 'max:255'],
                'customer_phone_snapshot'   => ['nullable', 'string', 'max:50'],
                'delivery_address_snapshot' => ['nullable', 'string'],
                'scheduled_date'            => ['nullable', 'date'],
                'scheduled_time'            => ['nullable'],
                'notes'                     => ['nullable', 'string'],
                'sales_order_number'        => ['nullable', 'string', 'max:100'],
                'order_discount_amount'     => ['nullable', 'numeric', 'min:0'],
                'items'                     => ['required', 'array', 'min:1'],
                'items.*.menu_item_id'      => ['required', 'integer'],
                'items.*.quantity'          => ['required', 'numeric', 'min:0.001'],
                'items.*.discount_amount'   => ['nullable', 'numeric', 'min:0'],
            ])->validate();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
            return;
        }

        $newImages = collect($this->e_new_images)
            ->filter(fn ($f) => $f instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
            ->values()->all();

        try {
            $service->update($order, $validated, $newImages, $this->e_remove_image_ids);
            session()->flash('status_message', __('Pastry order updated.'));
            $this->closeEditDrawer();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $msgs) {
                foreach ($msgs as $m) { $this->addError($field, $m); }
            }
        }
    }

    // ── Quick status ──────────────────────────────────────────────────────────

    public function quickStatus(int $orderId, string $newStatus): void
    {
        $order = PastryOrder::find($orderId);
        if (! $order || $order->isInvoiced()) return;
        $order->update(['status' => $newStatus]);
        session()->flash('status_message', __('Status updated.'));
    }

    // ── View drawer ───────────────────────────────────────────────────────────

    public function openViewDrawer(int $orderId, PastryOrderImageService $imageService): void
    {
        $order = PastryOrder::with(['items', 'images'])->find($orderId);
        if (! $order) return;

        $this->v_order_id             = $order->id;
        $this->v_order_number         = $order->order_number;
        $this->v_sales_order_number   = $order->sales_order_number;
        $this->v_customer_name        = $order->customer_name_snapshot ?? '';
        $this->v_customer_phone       = $order->customer_phone_snapshot;
        $this->v_status         = $order->status;
        $this->v_scheduled_date = $order->scheduled_date?->format('Y-m-d');
        $this->v_type           = $order->type;
        $this->v_notes          = $order->notes;
        $this->v_items          = $order->items->sortBy('sort_order')
            ->map(fn ($i) => ['description' => $i->description_snapshot, 'quantity' => (float) $i->quantity])
            ->values()->toArray();
        $this->v_images         = $imageService->presignedUrlsForOrder($order);
        $this->showViewDrawer   = true;
    }

    public function closeViewDrawer(): void
    {
        $this->showViewDrawer = false;
        $this->v_order_id     = null;
    }

    // ── Query ─────────────────────────────────────────────────────────────────

    private function buildQuery()
    {
        return PastryOrder::query()
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->when($this->branch_id, fn ($q) => $q->where('branch_id', $this->branch_id))
            ->when($this->scheduled_date, fn ($q) => $q->whereDate('scheduled_date', $this->scheduled_date))
            ->when($this->search, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($qq) => $qq
                    ->where('order_number', 'like', $term)
                    ->orWhere('customer_name_snapshot', 'like', $term)
                    ->orWhere('customer_phone_snapshot', 'like', $term)
                );
            })
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->withCount('items')
            ->with('images');
    }
}; ?>

<div class="app-page space-y-6">

@php
    $selectClass = 'w-full rounded-md border border-neutral-200 bg-white px-3 py-2.5 text-base text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50 touch-target';
    $labelClass  = 'block text-sm font-medium text-neutral-700 dark:text-neutral-200 mb-1';
    $searchUrl   = route('pastry-orders.menu-items.search');
@endphp

<style>
    .po-drawer { position:fixed;inset:0;z-index:99999;pointer-events:none; }
    .po-drawer[data-open="1"] { pointer-events:auto; }
    .po-drawer__backdrop { position:absolute;inset:0;background:rgba(0,0,0,.45);opacity:0;transition:opacity 200ms ease; }
    .po-drawer[data-open="1"] .po-drawer__backdrop { opacity:1; }
    .po-drawer__panel { position:absolute;top:0;right:0;height:100%;width:100%;max-width:560px;transform:translateX(100%);transition:transform 250ms ease;background:#fff;box-shadow:-20px 0 60px rgba(0,0,0,.2);border-left:1px solid rgba(0,0,0,.08);overflow-y:auto; }
    .po-drawer__panel--wide { max-width:760px; }
    .po-drawer[data-open="1"] .po-drawer__panel { transform:translateX(0); }
    .dark .po-drawer__panel { background:rgb(23 23 23);border-left-color:rgba(255,255,255,.12); }
    .touch-target { min-height:44px;touch-action:manipulation; }
    .po-img-thumb { position:relative;display:inline-block; }
    .po-img-thumb__del { position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:50%;background:rgba(239,68,68,.9);color:#fff;font-size:13px;line-height:20px;text-align:center;cursor:pointer;border:0; }
</style>

    {{-- Header --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Pastry Orders') }}</h1>
        <div class="flex flex-wrap gap-2">
            @can('pastry-orders.manage')
            <flux:button type="button" wire:click="openCreateDrawer" variant="primary" class="min-h-[44px] touch-manipulation">
                {{ __('New Pastry Order') }}
            </flux:button>
            @endcan
            <flux:button :href="route('pastry-orders.print-all', ['status' => $status, 'type' => $type, 'branch_id' => $branch_id, 'scheduled_date' => $scheduled_date, 'search' => $search])" target="_blank" variant="ghost">
                {{ __('Print All') }}
            </flux:button>
            <flux:button :href="route('reports.pastry-orders.csv', ['status' => $status, 'type' => $type, 'branch_id' => $branch_id, 'scheduled_date' => $scheduled_date, 'search' => $search])" variant="ghost">
                {{ __('Export CSV') }}
            </flux:button>
            <flux:button :href="route('reports.pastry-orders')" wire:navigate variant="ghost">
                {{ __('Reports') }}
            </flux:button>
        </div>
    </div>

    {{-- Flash --}}
    @if (session('status_message'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">{{ session('status_message') }}</div>
    @endif
    @if (session('error_message'))
        <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">{{ session('error_message') }}</div>
    @endif

    {{-- Filters --}}
    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
        <div class="app-filter-grid">
            <div class="min-w-[220px] flex-1">
                <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" placeholder="{{ __('Order # / customer / phone') }}" />
            </div>
            <div class="w-40">
                <flux:input wire:model.live="scheduled_date" type="date" :label="__('Date')" />
            </div>
            <div class="w-40">
                <label class="{{ $labelClass }}">{{ __('Status') }}</label>
                <select wire:model.live="status" class="{{ $selectClass }}">
                    <option value="all">{{ __('All') }}</option>
                    <option value="Draft">{{ __('Draft') }}</option>
                    <option value="Confirmed">{{ __('Confirmed') }}</option>
                    <option value="InProduction">{{ __('In Production') }}</option>
                    <option value="Ready">{{ __('Ready') }}</option>
                    <option value="Delivered">{{ __('Delivered') }}</option>
                    <option value="Cancelled">{{ __('Cancelled') }}</option>
                </select>
            </div>
            <div class="w-36">
                <label class="{{ $labelClass }}">{{ __('Type') }}</label>
                <select wire:model.live="type" class="{{ $selectClass }}">
                    <option value="">{{ __('All') }}</option>
                    <option value="Pickup">{{ __('Pickup') }}</option>
                    <option value="Delivery">{{ __('Delivery') }}</option>
                </select>
            </div>
            <div class="w-28">
                <flux:input wire:model.live="branch_id" type="number" :label="__('Branch')" />
            </div>
        </div>
    </div>

    {{-- Mobile cards --}}
    <div class="app-mobile-card-grid">
        @forelse ($orders as $order)
            @php $firstImg = $order->images->first(); @endphp
            <article class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 space-y-3">
                <div class="flex items-start gap-3">
                    @if ($firstImg)
                        <img src="{{ app(\App\Services\PastryOrders\PastryOrderImageService::class)->presignedUrl($firstImg->image_path) }}"
                             alt="{{ __('Order image') }}"
                             class="h-24 w-24 flex-shrink-0 rounded-lg object-cover border border-neutral-200 dark:border-neutral-700" />
                    @else
                        @php
                            $mParts = preg_split('/\s+/', trim($order->customer_name_snapshot ?? '?'));
                            $mInitials = strtoupper(mb_substr($mParts[0], 0, 1) . (count($mParts) > 1 ? mb_substr(end($mParts), 0, 1) : ''));
                        @endphp
                        <div class="flex h-24 w-24 flex-shrink-0 items-center justify-center rounded-lg border border-dashed border-neutral-300 bg-neutral-100 dark:border-neutral-600 dark:bg-neutral-800">
                            <span class="text-xl font-bold text-neutral-400 dark:text-neutral-500 select-none">{{ $mInitials }}</span>
                        </div>
                    @endif
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-neutral-900 dark:text-neutral-100 truncate">{{ $order->order_number }}</p>
                            <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 whitespace-nowrap">{{ $order->status }}</span>
                        </div>
                        <p class="mt-0.5 text-sm text-neutral-700 dark:text-neutral-300 truncate">{{ $order->customer_name_snapshot ?? '—' }}</p>
                        @if ($order->customer_phone_snapshot)
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $order->customer_phone_snapshot }}</p>
                        @endif
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 text-sm">
                    <div><p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Type') }}</p><p class="text-neutral-800 dark:text-neutral-100">{{ $order->type }}</p></div>
                    <div><p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Branch') }}</p><p class="text-neutral-800 dark:text-neutral-100">{{ $order->branch_id ?? '—' }}</p></div>
                    <div><p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Items') }}</p><p class="text-neutral-800 dark:text-neutral-100">{{ $order->items_count }}</p></div>
                    <div><p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Total') }}</p><p class="font-semibold text-neutral-900 dark:text-neutral-100">{{ number_format((float)$order->total_amount, 3) }}</p></div>
                    @if ($order->sales_order_number)
                        <div class="col-span-2"><p class="text-xs text-neutral-500 dark:text-neutral-400">{{ __('Sales Order #') }}</p><p class="text-neutral-800 dark:text-neutral-100">{{ $order->sales_order_number }}</p></div>
                    @endif
                </div>
                <p class="text-xs text-neutral-500 dark:text-neutral-400">
                    {{ __('Scheduled') }}: {{ $order->scheduled_date?->format('Y-m-d') ?? '—' }}{{ $order->scheduled_time ? ' · '.$order->scheduled_time : '' }}
                </p>
                @if ($order->notes)
                    <p class="rounded-md bg-neutral-50 px-2 py-1 text-xs text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        {{ __('Notes') }}: {{ $order->notes }}
                    </p>
                @endif
                @if ($order->isInvoiced())
                    <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-100">{{ __('Invoiced') }}</span>
                @endif
                <div class="flex flex-wrap gap-2">
                    @if (! $order->isInvoiced())
                        @if ($order->status === 'Draft')
                            <flux:button size="sm" type="button" variant="primary" wire:click="quickStatus({{ $order->id }},'Confirmed')" class="touch-target">{{ __('Confirm') }}</flux:button>
                        @elseif ($order->status === 'Confirmed')
                            <flux:button size="sm" type="button" variant="primary" wire:click="quickStatus({{ $order->id }},'InProduction')" class="touch-target">{{ __('Start Production') }}</flux:button>
                        @elseif ($order->status === 'InProduction')
                            <flux:button size="sm" type="button" variant="primary" wire:click="quickStatus({{ $order->id }},'Ready')" class="touch-target">{{ __('Mark Ready') }}</flux:button>
                        @elseif ($order->status === 'Ready')
                            <flux:button size="sm" type="button" variant="primary" wire:click="quickStatus({{ $order->id }},'Delivered')" class="touch-target">{{ __('Mark Delivered') }}</flux:button>
                        @endif
                        <flux:button size="sm" type="button" wire:click="openViewDrawer({{ $order->id }})" class="touch-target">{{ __('View') }}</flux:button>
                        @can('pastry-orders.manage')
                        <flux:button size="sm" type="button" wire:click="openEditDrawer({{ $order->id }})" class="touch-target">{{ __('Edit') }}</flux:button>
                        @endcan
                        @can('pastry-orders.manage')
                        @if (! in_array($order->status, ['Cancelled','Delivered'], true))
                            <flux:button size="sm" type="button" variant="danger" wire:click="quickStatus({{ $order->id }},'Cancelled')" wire:confirm="{{ __('Cancel this order?') }}" class="touch-target">{{ __('Cancel') }}</flux:button>
                        @endif
                        @endcan
                        <flux:button size="sm" :href="route('pastry-orders.print-single', ['order' => $order->id])" target="_blank" class="touch-target">
                            {{ __('Print') }}
                        </flux:button>
                    @else
                        <flux:button size="sm" type="button" wire:click="openViewDrawer({{ $order->id }})" class="touch-target">{{ __('View') }}</flux:button>
                        <flux:button size="sm" :href="route('pastry-orders.print-single', ['order' => $order->id])" target="_blank" class="touch-target">
                            {{ __('Print') }}
                        </flux:button>
                    @endif
                </div>
            </article>
        @empty
            <div class="col-span-full rounded-xl border border-neutral-200 bg-white px-4 py-8 text-center text-sm text-neutral-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                {{ __('No pastry orders found.') }}
            </div>
        @endforelse
    </div>

    {{-- Desktop table --}}
    <div class="app-desktop-table app-table-shell">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100 w-12"></th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Order #') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Sales Order #') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Status') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Customer / Notes') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Scheduled') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Branch') }}</th>
                    <th class="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Total') }}</th>
                    <th class="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wider text-neutral-700 dark:text-neutral-100">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($orders as $order)
                    @php $firstImg = $order->images->first(); @endphp
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 align-middle">
                            @if ($firstImg)
                                <img src="{{ app(\App\Services\PastryOrders\PastryOrderImageService::class)->presignedUrl($firstImg->image_path) }}"
                                     alt="" class="h-20 w-20 rounded-lg object-cover border border-neutral-200 dark:border-neutral-700" />
                            @else
                                @php
                                    $dParts = preg_split('/\s+/', trim($order->customer_name_snapshot ?? '?'));
                                    $dInitials = strtoupper(mb_substr($dParts[0], 0, 1) . (count($dParts) > 1 ? mb_substr(end($dParts), 0, 1) : ''));
                                @endphp
                                <div class="flex h-20 w-20 items-center justify-center rounded-lg border border-dashed border-neutral-300 bg-neutral-100 dark:border-neutral-600 dark:bg-neutral-800">
                                    <span class="text-base font-bold text-neutral-400 dark:text-neutral-500 select-none">{{ $dInitials }}</span>
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-900 dark:text-neutral-100 align-middle font-medium">{{ $order->order_number }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200 align-middle">{{ $order->sales_order_number ?? '—' }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200 align-middle">{{ $order->status }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200 align-middle">{{ $order->type }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200 align-middle">
                            <div class="font-medium">{{ $order->customer_name_snapshot ?? '—' }}</div>
                            @if ($order->notes)
                                <div class="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">{{ \Illuminate\Support\Str::limit($order->notes, 140) }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200 align-middle">{{ $order->scheduled_date?->format('Y-m-d') ?? '—' }}</td>
                        <td class="px-3 py-3 text-sm text-neutral-700 dark:text-neutral-200 align-middle">{{ $order->branch_id ?? '—' }}</td>
                        <td class="px-3 py-3 text-sm text-right text-neutral-900 dark:text-neutral-100 align-middle font-semibold">{{ number_format((float)$order->total_amount, 3) }}</td>
                        <td class="px-3 py-3 text-sm text-right align-middle">
                            <div class="flex flex-wrap justify-end gap-2">
                                @if (! $order->isInvoiced())
                                    @if ($order->status === 'Draft')
                                        <flux:button size="sm" type="button" variant="primary" wire:click="quickStatus({{ $order->id }},'Confirmed')" class="min-h-[44px]">{{ __('Confirm') }}</flux:button>
                                    @elseif ($order->status === 'Confirmed')
                                        <flux:button size="sm" type="button" variant="primary" wire:click="quickStatus({{ $order->id }},'InProduction')" class="min-h-[44px]">{{ __('Start Production') }}</flux:button>
                                    @elseif ($order->status === 'InProduction')
                                        <flux:button size="sm" type="button" variant="primary" wire:click="quickStatus({{ $order->id }},'Ready')" class="min-h-[44px]">{{ __('Mark Ready') }}</flux:button>
                                    @elseif ($order->status === 'Ready')
                                        <flux:button size="sm" type="button" variant="primary" wire:click="quickStatus({{ $order->id }},'Delivered')" class="min-h-[44px]">{{ __('Mark Delivered') }}</flux:button>
                                    @endif
                                    <flux:button size="sm" type="button" wire:click="openViewDrawer({{ $order->id }})" class="min-h-[44px]">{{ __('View') }}</flux:button>
                                    @can('pastry-orders.manage')
                                    <flux:button size="sm" type="button" wire:click="openEditDrawer({{ $order->id }})" class="min-h-[44px]">{{ __('Edit') }}</flux:button>
                                    @endcan
                                    <flux:button size="sm" :href="route('pastry-orders.print-single', ['order' => $order->id])" target="_blank" class="min-h-[44px]">{{ __('Print') }}</flux:button>
                                    @can('pastry-orders.manage')
                                    @if (! in_array($order->status, ['Cancelled','Delivered'], true))
                                        <flux:button size="sm" type="button" variant="danger" wire:click="quickStatus({{ $order->id }},'Cancelled')" wire:confirm="{{ __('Cancel this order?') }}" class="min-h-[44px]">{{ __('Cancel') }}</flux:button>
                                    @endif
                                    @endcan
                                @else
                                    <flux:button size="sm" type="button" wire:click="openViewDrawer({{ $order->id }})" class="min-h-[44px]">{{ __('View') }}</flux:button>
                                    <flux:button size="sm" :href="route('pastry-orders.print-single', ['order' => $order->id])" target="_blank" class="min-h-[44px]">{{ __('Print') }}</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-8 text-center text-sm text-neutral-500 dark:text-neutral-400">{{ __('No pastry orders found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $orders->links() }}</div>

    {{-- ===== CREATE DRAWER ===== --}}
    <div class="po-drawer" data-open="{{ $showCreateDrawer ? '1' : '0' }}" role="dialog" aria-modal="true" @if(! $showCreateDrawer) inert @endif>
        <div class="po-drawer__backdrop" wire:click="closeCreateDrawer"></div>
        <div class="po-drawer__panel">
            <div class="sticky top-0 z-10 border-b border-neutral-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-neutral-700 dark:bg-neutral-900/95 flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('New Pastry Order') }}</h2>
                <flux:button size="sm" type="button" variant="ghost" wire:click="closeCreateDrawer" class="touch-target">{{ __('Close') }}</flux:button>
            </div>
            @if ($showCreateDrawer)
            <form wire:submit="saveCreate" class="p-4 space-y-4">

                @if ($errors->any())
                    <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
                        <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif

                {{-- Branch / Type / Status --}}
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <label class="{{ $labelClass }}">{{ __('Branch') }}</label>
                        @if ($branches->count())
                            <select wire:model="c_branch_id" class="{{ $selectClass }}">
                                @foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
                            </select>
                        @else
                            <flux:input wire:model="c_branch_id" type="number" />
                        @endif
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">{{ __('Type') }}</label>
                        <select wire:model="c_type" class="{{ $selectClass }}">
                            <option value="Pickup">{{ __('Pickup') }}</option>
                            <option value="Delivery">{{ __('Delivery') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">{{ __('Status') }}</label>
                        <select wire:model="c_status" class="{{ $selectClass }}">
                            <option value="Draft">{{ __('Draft') }}</option>
                            <option value="Confirmed">{{ __('Confirmed') }}</option>
                            <option value="InProduction">{{ __('In Production') }}</option>
                            <option value="Ready">{{ __('Ready') }}</option>
                            <option value="Delivered">{{ __('Delivered') }}</option>
                            <option value="Cancelled">{{ __('Cancelled') }}</option>
                        </select>
                    </div>
                </div>

                {{-- Customer search --}}
                <div>
                    <flux:input wire:model.live.debounce.300ms="c_customer_search" :label="__('Customer')" placeholder="{{ __('Search by name or phone') }}" />
                    @if ($createCustomers->count() && $c_customer_id === null)
                        <ul class="mt-1 rounded-md border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-800 divide-y divide-neutral-100 dark:divide-neutral-700">
                            @foreach ($createCustomers as $customer)
                                <li wire:click="selectCreateCustomer({{ $customer->id }})"
                                    class="cursor-pointer px-3 py-2 text-sm text-neutral-800 dark:text-neutral-100 hover:bg-neutral-50 dark:hover:bg-neutral-700">
                                    {{ $customer->name }} <span class="text-xs text-neutral-500">{{ $customer->phone }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($c_customer_id)
                        <p class="mt-1 text-xs text-emerald-600 dark:text-emerald-400">{{ __('Linked to customer account') }}</p>
                    @endif
                </div>

                {{-- Delivery address --}}
                <div x-show="$wire.c_type === 'Delivery'" x-cloak>
                    <flux:input wire:model="c_delivery_address" :label="__('Delivery Address')" />
                </div>

                {{-- Scheduled date / time --}}
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <flux:input wire:model="c_scheduled_date" type="date" :label="__('Scheduled Date')" />
                    <flux:input wire:model="c_scheduled_time" type="time" :label="__('Scheduled Time')" />
                </div>

                {{-- Sales Order Number --}}
                <div>
                    <flux:input wire:model="c_sales_order_number" :label="__('Sales Order #')" placeholder="{{ __('Optional') }}" />
                </div>

                {{-- Notes --}}
                <div>
                    <label class="{{ $labelClass }}">{{ __('Notes') }}</label>
                    <textarea wire:model="c_notes" rows="2"
                        class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"></textarea>
                </div>

                {{-- Images upload (multiple) --}}
                <div>
                    <label class="{{ $labelClass }}">{{ __('Images') }} <span class="text-xs text-neutral-400">({{ __('optional, multiple') }})</span></label>
                    <input type="file" wire:model="c_images" accept="image/*" multiple
                        class="block w-full text-sm text-neutral-700 dark:text-neutral-200 file:mr-4 file:rounded-md file:border-0 file:bg-neutral-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-neutral-700 hover:file:bg-neutral-200 dark:file:bg-neutral-700 dark:file:text-neutral-100 dark:hover:file:bg-neutral-600" />
                    <div wire:loading wire:target="c_images" class="mt-1 text-xs text-neutral-500">{{ __('Uploading…') }}</div>
                    @if (! empty($c_images))
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($c_images as $img)
                                @if ($img instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                                    <img src="{{ $img->temporaryUrl() }}" class="h-16 w-16 rounded-lg object-cover border border-neutral-200 dark:border-neutral-700" alt="" />
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Line items --}}
                <div>
                    <label class="{{ $labelClass }}">{{ __('Items') }}</label>
                    <div class="space-y-1">
                        {{-- Column headers --}}
                        <div class="grid gap-x-2 px-1" style="grid-template-columns: minmax(0,1fr) 72px 80px 28px;">
                            <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">{{ __('Item') }}</span>
                            <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">{{ __('Qty') }}</span>
                            <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">{{ __('Disc.') }}</span>
                            <span></span>
                        </div>
                        @foreach ($c_items as $idx => $row)
                            <div class="grid gap-x-2 items-center" style="grid-template-columns: minmax(0,1fr) 72px 80px 28px;" wire:key="c-item-{{ $idx }}">
                                {{-- Alpine search — wire:ignore only on this div --}}
                                <div wire:ignore
                                     x-data="pastryItemLookup({
                                         index: {{ $idx }},
                                         initial: @js($c_item_search[$idx] ?? ''),
                                         selectedId: @js($row['menu_item_id'] ?? null),
                                         searchUrl: @js($searchUrl),
                                         selectMethod: 'selectCreateMenuItem',
                                         clearMethod: 'clearCreateMenuItemSearch'
                                     })"
                                     x-init="init()"
                                     x-on:keydown.escape.stop="close()"
                                     x-on:click.outside="close()">
                                    <input x-ref="input" type="text"
                                        class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                                        x-model="query"
                                        x-on:input.debounce.200ms="onInput()"
                                        x-on:focus="onInput(true)"
                                        placeholder="{{ __('Search item…') }}"
                                        autocomplete="off" />
                                    <template x-teleport="body">
                                        <div x-show="open" x-ref="panel" x-bind:style="panelStyle"
                                             class="z-[200000] overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                                            <div class="max-h-60 overflow-auto">
                                                <template x-for="item in results" :key="item.id">
                                                    <button type="button"
                                                        class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80"
                                                        x-on:click="choose(item)">
                                                        <div class="flex items-center justify-between gap-2">
                                                            <span class="font-medium" x-text="item.name"></span>
                                                            <span class="text-xs text-neutral-500 dark:text-neutral-400" x-show="item.code" x-text="item.code"></span>
                                                        </div>
                                                        <div class="text-xs text-neutral-500 dark:text-neutral-400" x-show="item.price_formatted" x-text="item.price_formatted"></div>
                                                    </button>
                                                </template>
                                                <div x-show="loading" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Searching…') }}</div>
                                                <div x-show="!loading && hasSearched && results.length === 0" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No items found.') }}</div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                                {{-- Qty — plain input, fully managed by Livewire --}}
                                <input wire:model="c_items.{{ $idx }}.quantity"
                                       type="number" step="0.001" min="0.001"
                                       class="w-full rounded-md border border-neutral-200 bg-white px-2 py-2 text-sm text-center text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                                       placeholder="1" />
                                {{-- Disc --}}
                                <input wire:model="c_items.{{ $idx }}.discount_amount"
                                       type="number" step="0.001" min="0"
                                       class="w-full rounded-md border border-neutral-200 bg-white px-2 py-2 text-sm text-center text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                                       placeholder="0" />
                                {{-- Remove --}}
                                @if (count($c_items) > 1)
                                    <button type="button" wire:click="removeCreateItem({{ $idx }})"
                                        class="w-7 h-7 flex items-center justify-center rounded text-neutral-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                @else
                                    <span></span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <button type="button" wire:click="addCreateItem"
                        class="mt-2 text-sm text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300">
                        + {{ __('Add Item') }}
                    </button>
                </div>

                {{-- Order discount --}}
                <flux:input wire:model="c_order_discount" type="number" step="0.001" min="0" :label="__('Order Discount')" />

                <div class="sticky bottom-0 -mx-4 px-4 py-3 flex justify-end gap-3 border-t border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                    <flux:button type="button" variant="ghost" wire:click="closeCreateDrawer">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Create Order') }}</flux:button>
                </div>
            </form>
            @endif
        </div>
    </div>

    {{-- ===== EDIT DRAWER ===== --}}
    <div class="po-drawer" data-open="{{ $showEditDrawer ? '1' : '0' }}" role="dialog" aria-modal="true" @if(! $showEditDrawer) inert @endif>
        <div class="po-drawer__backdrop" wire:click="closeEditDrawer"></div>
        <div class="po-drawer__panel">
            <div class="sticky top-0 z-10 border-b border-neutral-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-neutral-700 dark:bg-neutral-900/95 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                        {{ $e_is_invoiced ? __('View Pastry Order') : __('Edit Pastry Order') }}
                    </h2>
                    @if ($e_order_number)
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $e_order_number }}</p>
                    @endif
                </div>
                <flux:button size="sm" type="button" variant="ghost" wire:click="closeEditDrawer" class="touch-target">{{ __('Close') }}</flux:button>
            </div>
            @if ($showEditDrawer)
            <form wire:submit="saveEdit" class="p-4 space-y-4">

                @if ($errors->any())
                    <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-100">
                        <ul class="list-inside list-disc space-y-1">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif

                @if ($e_is_invoiced)
                    <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                        {{ __('This order has been invoiced and is read-only.') }}
                    </div>
                @endif

                <fieldset @if($e_is_invoiced) disabled @endif class="space-y-4">

                    {{-- Branch / Type / Status --}}
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label class="{{ $labelClass }}">{{ __('Branch') }}</label>
                            @if ($branches->count())
                                <select wire:model="e_branch_id" class="{{ $selectClass }}">
                                    @foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
                                </select>
                            @else
                                <flux:input wire:model="e_branch_id" type="number" />
                            @endif
                        </div>
                        <div>
                            <label class="{{ $labelClass }}">{{ __('Type') }}</label>
                            <select wire:model="e_type" class="{{ $selectClass }}">
                                <option value="Pickup">{{ __('Pickup') }}</option>
                                <option value="Delivery">{{ __('Delivery') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="{{ $labelClass }}">{{ __('Status') }}</label>
                            <select wire:model="e_status" class="{{ $selectClass }}">
                                <option value="Draft">{{ __('Draft') }}</option>
                                <option value="Confirmed">{{ __('Confirmed') }}</option>
                                <option value="InProduction">{{ __('In Production') }}</option>
                                <option value="Ready">{{ __('Ready') }}</option>
                                <option value="Delivered">{{ __('Delivered') }}</option>
                                <option value="Cancelled">{{ __('Cancelled') }}</option>
                            </select>
                        </div>
                    </div>

                    {{-- Customer --}}
                    <div>
                        <flux:input wire:model.live.debounce.300ms="e_customer_search" :label="__('Customer')" placeholder="{{ __('Search by name or phone') }}" />
                        @if ($editCustomers->count() && $e_customer_id === null)
                            <ul class="mt-1 rounded-md border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-800 divide-y divide-neutral-100 dark:divide-neutral-700">
                                @foreach ($editCustomers as $customer)
                                    <li wire:click="selectEditCustomer({{ $customer->id }})"
                                        class="cursor-pointer px-3 py-2 text-sm text-neutral-800 dark:text-neutral-100 hover:bg-neutral-50 dark:hover:bg-neutral-700">
                                        {{ $customer->name }} <span class="text-xs text-neutral-500">{{ $customer->phone }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($e_customer_id)
                            <p class="mt-1 text-xs text-emerald-600 dark:text-emerald-400">{{ __('Linked to customer account') }}</p>
                        @endif
                    </div>

                    <div x-show="$wire.e_type === 'Delivery'" x-cloak>
                        <flux:input wire:model="e_delivery_address" :label="__('Delivery Address')" />
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <flux:input wire:model="e_scheduled_date" type="date" :label="__('Scheduled Date')" />
                        <flux:input wire:model="e_scheduled_time" type="time" :label="__('Scheduled Time')" />
                    </div>

                    <div>
                        <flux:input wire:model="e_sales_order_number" :label="__('Sales Order #')" placeholder="{{ __('Optional') }}" />
                    </div>

                    <div>
                        <label class="{{ $labelClass }}">{{ __('Notes') }}</label>
                        <textarea wire:model="e_notes" rows="2"
                            class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"></textarea>
                    </div>

                    {{-- Images --}}
                    <div>
                        <label class="{{ $labelClass }}">{{ __('Images') }}</label>
                        @if (! empty($e_existing_images))
                            <div class="mb-3 flex flex-wrap gap-3">
                                @foreach ($e_existing_images as $img)
                                    @php $markedForRemoval = in_array($img['id'], $e_remove_image_ids, true); @endphp
                                    <div class="po-img-thumb {{ $markedForRemoval ? 'opacity-40' : '' }}">
                                        <img src="{{ $img['url'] }}" class="h-20 w-20 rounded-lg object-cover border border-neutral-200 dark:border-neutral-700" alt="" />
                                        @if (! $markedForRemoval)
                                            <button type="button" class="po-img-thumb__del"
                                                wire:click="markImageForRemoval({{ $img['id'] }})"
                                                title="{{ __('Remove') }}">×</button>
                                        @else
                                            <button type="button"
                                                class="absolute bottom-0 left-0 right-0 rounded-b-lg bg-amber-500/90 text-white text-xs py-0.5 px-1"
                                                wire:click="undoImageRemoval({{ $img['id'] }})">{{ __('Undo') }}</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @if (! $e_is_invoiced)
                            <input type="file" wire:model="e_new_images" accept="image/*" multiple
                                class="block w-full text-sm text-neutral-700 dark:text-neutral-200 file:mr-4 file:rounded-md file:border-0 file:bg-neutral-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-neutral-700 hover:file:bg-neutral-200 dark:file:bg-neutral-700 dark:file:text-neutral-100 dark:hover:file:bg-neutral-600" />
                            <div wire:loading wire:target="e_new_images" class="mt-1 text-xs text-neutral-500">{{ __('Uploading…') }}</div>
                            @if (! empty($e_new_images))
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($e_new_images as $img)
                                        @if ($img instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                                            <img src="{{ $img->temporaryUrl() }}" class="h-16 w-16 rounded-lg object-cover border border-neutral-200 dark:border-neutral-700" alt="" />
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </div>

                    {{-- Line items --}}
                    <div>
                        <label class="{{ $labelClass }}">{{ __('Items') }}</label>
                        <div class="space-y-1">
                            {{-- Column headers --}}
                            <div class="grid gap-x-2 px-1" style="grid-template-columns: minmax(0,1fr) 72px 80px 28px;">
                                <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">{{ __('Item') }}</span>
                                <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">{{ __('Qty') }}</span>
                                <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">{{ __('Disc.') }}</span>
                                <span></span>
                            </div>
                            @foreach ($e_items as $idx => $row)
                                <div class="grid gap-x-2 items-center" style="grid-template-columns: minmax(0,1fr) 72px 80px 28px;" wire:key="e-item-{{ $idx }}">
                                    {{-- Alpine search — wire:ignore only on this div --}}
                                    <div wire:ignore
                                         x-data="pastryItemLookup({
                                             index: {{ $idx }},
                                             initial: @js($e_item_search[$idx] ?? ''),
                                             selectedId: @js($row['menu_item_id'] ?? null),
                                             searchUrl: @js($searchUrl),
                                             selectMethod: 'selectEditMenuItem',
                                             clearMethod: 'clearEditMenuItemSearch'
                                         })"
                                         x-init="init()"
                                         x-on:keydown.escape.stop="close()"
                                         x-on:click.outside="close()">
                                        <input x-ref="input" type="text"
                                            class="w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                                            x-model="query"
                                            x-on:input.debounce.200ms="onInput()"
                                            x-on:focus="onInput(true)"
                                            placeholder="{{ __('Search item…') }}"
                                            autocomplete="off" />
                                        <template x-teleport="body">
                                            <div x-show="open" x-ref="panel" x-bind:style="panelStyle"
                                                 class="z-[200000] overflow-hidden rounded-md border border-neutral-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                                                <div class="max-h-60 overflow-auto">
                                                    <template x-for="item in results" :key="item.id">
                                                        <button type="button"
                                                            class="w-full px-3 py-2 text-left text-sm text-neutral-800 hover:bg-neutral-50 dark:text-neutral-100 dark:hover:bg-neutral-800/80"
                                                            x-on:click="choose(item)">
                                                            <div class="flex items-center justify-between gap-2">
                                                                <span class="font-medium" x-text="item.name"></span>
                                                                <span class="text-xs text-neutral-500 dark:text-neutral-400" x-show="item.code" x-text="item.code"></span>
                                                            </div>
                                                            <div class="text-xs text-neutral-500 dark:text-neutral-400" x-show="item.price_formatted" x-text="item.price_formatted"></div>
                                                        </button>
                                                    </template>
                                                    <div x-show="loading" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('Searching…') }}</div>
                                                    <div x-show="!loading && hasSearched && results.length === 0" class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">{{ __('No items found.') }}</div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                    {{-- Qty — plain input, fully managed by Livewire --}}
                                    <input wire:model="e_items.{{ $idx }}.quantity"
                                           type="number" step="0.001" min="0.001"
                                           class="w-full rounded-md border border-neutral-200 bg-white px-2 py-2 text-sm text-center text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                                           placeholder="1" />
                                    {{-- Disc --}}
                                    <input wire:model="e_items.{{ $idx }}.discount_amount"
                                           type="number" step="0.001" min="0"
                                           class="w-full rounded-md border border-neutral-200 bg-white px-2 py-2 text-sm text-center text-neutral-800 focus:border-primary-500 focus:ring-2 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50"
                                           placeholder="0" />
                                    {{-- Remove --}}
                                    @if (count($e_items) > 1)
                                        <button type="button" wire:click="removeEditItem({{ $idx }})"
                                            class="w-7 h-7 flex items-center justify-center rounded text-neutral-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    @else
                                        <span></span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <button type="button" wire:click="addEditItem"
                            class="mt-2 text-sm text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300">
                            + {{ __('Add Item') }}
                        </button>
                    </div>

                    <flux:input wire:model="e_order_discount" type="number" step="0.001" min="0" :label="__('Order Discount')" />

                </fieldset>

                @if (! $e_is_invoiced)
                    <div class="sticky bottom-0 -mx-4 px-4 py-3 flex justify-end gap-3 border-t border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                        <flux:button type="button" variant="ghost" wire:click="closeEditDrawer">{{ __('Cancel') }}</flux:button>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Save Changes') }}</flux:button>
                    </div>
                @else
                    <div class="sticky bottom-0 -mx-4 px-4 py-3 flex justify-end border-t border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                        <flux:button type="button" variant="ghost" wire:click="closeEditDrawer">{{ __('Close') }}</flux:button>
                    </div>
                @endif
            </form>
            @endif
        </div>
    </div>

    {{-- ===== VIEW DRAWER ===== --}}
    <div class="po-drawer" data-open="{{ $showViewDrawer ? '1' : '0' }}" role="dialog" aria-modal="true" @if(! $showViewDrawer) inert @endif>
        <div class="po-drawer__backdrop" wire:click="closeViewDrawer"></div>
        <div class="po-drawer__panel po-drawer__panel--wide">
            <div class="sticky top-0 z-10 border-b border-neutral-200 bg-white/95 px-5 py-3 backdrop-blur dark:border-neutral-700 dark:bg-neutral-900/95 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Order Details') }}</h2>
                    @if ($v_order_number)
                        <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $v_order_number }}</p>
                    @endif
                </div>
                <flux:button size="sm" type="button" variant="ghost" wire:click="closeViewDrawer" class="touch-target">{{ __('Close') }}</flux:button>
            </div>
            @if ($showViewDrawer)
            <div class="p-5">

                {{-- Two-column layout: image left, details right --}}
                <div class="flex gap-5 items-start">

                    {{-- Left: image --}}
                    <div class="w-64 flex-shrink-0">
                        @if (! empty($v_images))
                            <img src="{{ $v_images[0]['url'] }}" alt="{{ __('Order image') }}"
                                 class="w-full rounded-xl object-contain border border-neutral-200 dark:border-neutral-700" />
                        @else
                            @php
                                $vParts = preg_split('/\s+/', trim($v_customer_name ?? '?'));
                                $vInitials = strtoupper(mb_substr($vParts[0], 0, 1) . (count($vParts) > 1 ? mb_substr(end($vParts), 0, 1) : ''));
                            @endphp
                            <div class="w-full h-48 flex items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-100 dark:border-neutral-600 dark:bg-neutral-800">
                                <span class="text-5xl font-bold text-neutral-300 dark:text-neutral-600 select-none">{{ $vInitials }}</span>
                            </div>
                        @endif
                    </div>

                    {{-- Right: all details --}}
                    <div class="flex-1 min-w-0 space-y-4">

                        {{-- Items --}}
                        @if (! empty($v_items))
                            <div class="space-y-1.5">
                                <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500 dark:text-neutral-400">{{ __('Items') }}</p>
                                @foreach ($v_items as $vItem)
                                    <div class="flex items-center justify-between rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2.5 dark:border-neutral-700 dark:bg-neutral-800/60">
                                        <span class="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{{ $vItem['description'] }}</span>
                                        <span class="ml-3 text-sm font-medium text-neutral-500 dark:text-neutral-400 whitespace-nowrap">× {{ number_format($vItem['quantity'], 3) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Notes --}}
                        @if ($v_notes)
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 dark:border-amber-900 dark:bg-amber-950/50">
                                <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400 mb-1">{{ __('Notes') }}</p>
                                <p class="text-sm text-amber-900 dark:text-amber-100 whitespace-pre-wrap">{{ $v_notes }}</p>
                            </div>
                        @endif

                        {{-- Meta grid --}}
                        <div class="grid grid-cols-2 gap-2">
                            <div class="rounded-lg border border-neutral-200 bg-white px-3 py-2.5 dark:border-neutral-700 dark:bg-neutral-800/60">
                                <p class="text-xs text-neutral-500 dark:text-neutral-400 mb-0.5">{{ __('Status') }}</p>
                                <p class="text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $v_status }}</p>
                            </div>
                            <div class="rounded-lg border border-neutral-200 bg-white px-3 py-2.5 dark:border-neutral-700 dark:bg-neutral-800/60">
                                <p class="text-xs text-neutral-500 dark:text-neutral-400 mb-0.5">{{ __('Scheduled') }}</p>
                                <p class="text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $v_scheduled_date ?? '—' }}</p>
                            </div>
                            <div class="rounded-lg border border-neutral-200 bg-white px-3 py-2.5 dark:border-neutral-700 dark:bg-neutral-800/60">
                                <p class="text-xs text-neutral-500 dark:text-neutral-400 mb-0.5">{{ __('Customer') }}</p>
                                <p class="text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $v_customer_name ?: '—' }}</p>
                                @if ($v_customer_phone)
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400">{{ $v_customer_phone }}</p>
                                @endif
                            </div>
                            <div class="rounded-lg border border-neutral-200 bg-white px-3 py-2.5 dark:border-neutral-700 dark:bg-neutral-800/60">
                                <p class="text-xs text-neutral-500 dark:text-neutral-400 mb-0.5">{{ __('Type') }}</p>
                                <p class="text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $v_type ?: '—' }}</p>
                            </div>
                            @if ($v_sales_order_number)
                                <div class="col-span-2 rounded-lg border border-neutral-200 bg-white px-3 py-2.5 dark:border-neutral-700 dark:bg-neutral-800/60">
                                    <p class="text-xs text-neutral-500 dark:text-neutral-400 mb-0.5">{{ __('Sales Order #') }}</p>
                                    <p class="text-sm font-medium text-neutral-900 dark:text-neutral-100">{{ $v_sales_order_number }}</p>
                                </div>
                            @endif
                        </div>

                    </div>
                </div>

            </div>
            @endif
        </div>
    </div>

</div>

<script>
(function () {
    const register = () => {
        if (!window.Alpine || window.__pastryItemLookupRegistered) return;
        window.__pastryItemLookupRegistered = true;

        window.Alpine.data('pastryItemLookup', ({ index, initial, selectedId, searchUrl, selectMethod, clearMethod }) => ({
            index,
            query: initial || '',
            selectedId: selectedId || null,
            selectedLabel: initial || '',
            searchUrl,
            selectMethod,
            clearMethod,
            results: [],
            loading: false,
            open: false,
            hasSearched: false,
            panelStyle: '',
            controller: null,
            repositionHandler: null,

            init() {
                this.repositionHandler = () => { if (this.open) this.positionDropdown(); };
                window.addEventListener('resize', this.repositionHandler);
                window.addEventListener('scroll', this.repositionHandler, true);
            },

            onInput(force = false) {
                if (this.selectedId !== null && this.query !== this.selectedLabel) {
                    this.selectedId = null;
                    this.selectedLabel = '';
                    this.$wire[this.clearMethod](this.index);
                }
                const term = this.query.trim();
                if (!force && term.length < 2) { this.open = false; this.results = []; this.hasSearched = false; return; }
                if (term.length < 2) { this.open = false; this.results = []; this.hasSearched = false; return; }
                this.fetchResults(term);
            },

            fetchResults(term) {
                this.loading = true;
                this.hasSearched = true;
                this.open = true;
                this.positionDropdown();
                if (this.controller) this.controller.abort();
                this.controller = new AbortController();
                const params = new URLSearchParams({ q: term });
                fetch(this.searchUrl + '?' + params.toString(), {
                    headers: { 'Accept': 'application/json' },
                    signal: this.controller.signal,
                    credentials: 'same-origin',
                })
                .then(r => r.ok ? r.json() : [])
                .then(data => {
                    this.results = Array.isArray(data) ? data : [];
                    this.loading = false;
                    this.$nextTick(() => this.positionDropdown());
                })
                .catch(err => {
                    if (err.name === 'AbortError') return;
                    this.loading = false;
                    this.results = [];
                });
            },

            choose(item) {
                const label = item.label || item.name || '';
                this.query = label;
                this.selectedLabel = label;
                this.selectedId = item.id;
                this.open = false;
                this.results = [];
                this.loading = false;
                this.$wire[this.selectMethod](this.index, item.id, label);
            },

            close() { this.open = false; },

            positionDropdown() {
                if (!this.$refs.input) return;
                const rect = this.$refs.input.getBoundingClientRect();
                this.panelStyle = [
                    'position: fixed',
                    `top: ${rect.bottom + 4}px`,
                    `left: ${rect.left}px`,
                    `width: ${rect.width}px`,
                    'z-index: 999999',
                ].join('; ');
            },
        }));
    };

    if (window.Alpine) { register(); }
    else { document.addEventListener('alpine:init', register, { once: true }); }
})();
</script>

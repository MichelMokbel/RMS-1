<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\POS\PosCheckoutService;
use App\Services\POS\PosShiftService;
use App\Services\Sales\SaleService;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public int $branch_id = 1;
    public ?int $sale_id = null;
    public bool $is_admin = false;

    public string $order_type = 'takeaway';
    public ?int $category_id = null;
    public ?int $customer_id = null;
    public string $reference = '';
    public string $pos_date = '';
    public bool $is_credit = false;
    public string $invoiceDiscountType = 'fixed';
    public string $invoiceDiscountValue = '0.00';

    public string $search = '';
    public ?int $selected_menu_item_id = null;
    public string $qty = '1.000';
    public string $discount = '0.00';

    public bool $showPayModal = false;
    public bool $showHeldModal = false;
    public bool $showCustomerModal = false;
    public string $customerSearch = '';

    public array $lineQty = [];
    public array $linePrice = [];
    public array $lineDiscountType = [];
    public array $lineDiscountValue = [];
    public ?int $selected_item_id = null;

    public array $payments = [
        ['method' => 'cash', 'amount' => '0.00'],
    ];

    public function mount(SaleService $service): void
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        $this->is_admin = $user && method_exists($user, 'hasRole') ? $user->hasRole('admin') : false;

        if ($this->is_admin) {
            $selectedBranch = (int) session('pos_branch_id', 0);
            if ($selectedBranch <= 0) {
                $this->redirectRoute('pos.branch-select', navigate: true);
                return;
            }
            $this->branch_id = $selectedBranch;
        } else {
            $this->branch_id = (int) config('inventory.default_branch_id', 1) ?: 1;
        }

        $this->pos_date = now()->toDateString();
        $this->ensureSale($service);
    }

    public function ensureSale(SaleService $service): void
    {
        if ($this->sale_id) {
            return;
        }
        $userId = Auth::id();
        if (! $userId) {
            return;
        }
        $sale = $service->create([
            'branch_id' => $this->branch_id,
            'customer_id' => $this->customer_id,
            'currency' => config('pos.currency'),
            'order_type' => $this->order_type,
            'reference' => $this->reference ?: null,
            'pos_date' => $this->pos_date ?: now()->toDateString(),
            'source' => 'pos',
        ], $userId);
        $this->sale_id = $sale->id;
    }

    public function with(PosShiftService $shifts, SaleService $saleService): array
    {
        $userId = Auth::id();
        $activeShift = $userId ? $shifts->activeShiftFor($this->branch_id, $userId) : null;

        $sale = $this->sale_id ? Sale::with(['items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])->find($this->sale_id) : null;
        if ($sale && (int) $sale->branch_id !== (int) $this->branch_id) {
            $sale = null;
            $this->sale_id = null;
        }
        if ($sale && ! $sale->isOpen()) {
            $sale = null;
            $this->sale_id = null;
        }

        if ($sale) {
            foreach ($sale->items as $row) {
                $this->lineQty[$row->id] = $this->lineQty[$row->id] ?? (string) $row->qty;
                $this->linePrice[$row->id] = $this->linePrice[$row->id] ?? MinorUnits::format((int) $row->unit_price_cents, null, false);
                $this->lineDiscountType[$row->id] = $this->lineDiscountType[$row->id] ?? ($row->discount_type ?? 'fixed');
                if (($row->discount_type ?? 'fixed') === 'percent') {
                    $this->lineDiscountValue[$row->id] = $this->lineDiscountValue[$row->id] ?? $this->formatPercent((int) ($row->discount_value ?? 0));
                } else {
                    $this->lineDiscountValue[$row->id] = $this->lineDiscountValue[$row->id] ?? MinorUnits::format((int) ($row->discount_value ?? 0), null, false);
                }
            }

            $this->invoiceDiscountType = $sale->global_discount_type ?? 'fixed';
            if ($this->invoiceDiscountType === 'percent') {
                $this->invoiceDiscountValue = $this->formatPercent((int) ($sale->global_discount_value ?? 0));
            } else {
                $this->invoiceDiscountValue = MinorUnits::format((int) ($sale->global_discount_value ?? 0), null, false);
            }
            $this->is_credit = (bool) ($sale->is_credit ?? false);
            if ($sale->pos_date) {
                $this->pos_date = $sale->pos_date->toDateString();
            }
        }

        $menuItemsQuery = MenuItem::query()
            ->active()
            ->availableInBranch($this->branch_id)
            ->ordered();
        if ($this->category_id !== null && $this->category_id > 0) {
            $menuItemsQuery->where('category_id', $this->category_id);
        }
        if (trim($this->search) !== '') {
            $menuItemsQuery->search($this->search);
        }
        $menuItems = $menuItemsQuery->limit(100)->get();

        $categories = [];
        if (Schema::hasTable('categories')) {
            $categoryIds = MenuItem::query()
                ->active()
                ->availableInBranch($this->branch_id)
                ->whereNotNull('category_id')
                ->distinct()
                ->pluck('category_id');
            $categories = Category::query()
                ->whereIn('id', $categoryIds)
                ->orderBy('name')
                ->get()
                ->map(function (Category $cat) {
                    $count = MenuItem::query()
                        ->active()
                        ->availableInBranch($this->branch_id)
                        ->where('category_id', $cat->id)
                        ->count();
                    return (object) ['id' => $cat->id, 'name' => $cat->name, 'count' => $count];
                });
        }

        $takeawayOpen = Sale::query()
            ->where('branch_id', $this->branch_id)
            ->whereIn('status', ['draft', 'open'])
            ->whereNull('held_at')
            ->where('order_type', 'takeaway')
            ->count();
        $takeawayHeld = Sale::query()
            ->where('branch_id', $this->branch_id)
            ->whereNotNull('held_at')
            ->whereIn('status', ['draft', 'open'])
            ->where('order_type', 'takeaway')
            ->count();
        $dineInOpen = Sale::query()
            ->where('branch_id', $this->branch_id)
            ->whereIn('status', ['draft', 'open'])
            ->whereNull('held_at')
            ->where('order_type', 'dine_in')
            ->count();
        $dineInHeld = Sale::query()
            ->where('branch_id', $this->branch_id)
            ->whereNotNull('held_at')
            ->whereIn('status', ['draft', 'open'])
            ->where('order_type', 'dine_in')
            ->count();

        $heldList = $saleService->listHeld($this->branch_id);
        $heldCount = $heldList->count();

        $customers = collect();
        if ($this->showCustomerModal && trim($this->customerSearch) !== '') {
            $customers = Customer::query()
                ->where(function ($q) {
                    $term = '%'.trim($this->customerSearch).'%';
                    $q->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                })
                ->orderBy('name')
                ->limit(20)
                ->get();
        }

        return [
            'isAdmin' => $this->is_admin,
            'activeShift' => $activeShift,
            'sale' => $sale,
            'menuItems' => $menuItems,
            'categories' => $categories,
            'takeawayOpen' => $takeawayOpen,
            'takeawayHeld' => $takeawayHeld,
            'dineInOpen' => $dineInOpen,
            'dineInHeld' => $dineInHeld,
            'heldList' => $heldList,
            'heldCount' => $heldCount,
            'customers' => $customers,
            'branches' => Schema::hasTable('branches')
                ? DB::table('branches')->where('is_active', 1)->orderBy('name')->get()
                : collect(),
        ];
    }

    public function newSale(SaleService $service): void
    {
        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }
        $sale = $service->create([
            'branch_id' => $this->branch_id,
            'customer_id' => $this->customer_id,
            'currency' => config('pos.currency'),
            'order_type' => $this->order_type,
            'reference' => $this->reference ?: null,
            'pos_date' => $this->pos_date ?: now()->toDateString(),
            'source' => 'pos',
        ], $userId);
        $this->sale_id = $sale->id;
        $this->is_credit = false;
        $this->invoiceDiscountType = 'fixed';
        $this->invoiceDiscountValue = '0.00';
        $this->lineQty = [];
        $this->linePrice = [];
        $this->lineDiscountType = [];
        $this->lineDiscountValue = [];
        session()->flash('status', __('New order created.'));
    }

    public function setOrderType(string $type): void
    {
        if (in_array($type, ['takeaway', 'dine_in'], true)) {
            $this->order_type = $type;
            if ($this->sale_id) {
                Sale::whereKey($this->sale_id)->update(['order_type' => $type]);
            }
        }
    }

    public function setCategory(?int $id): void
    {
        $this->category_id = $id;
    }

    public function setCustomer(?int $id): void
    {
        $this->customer_id = $id;
        $this->showCustomerModal = false;
        $this->customerSearch = '';
        if ($this->sale_id) {
            Sale::whereKey($this->sale_id)->update(['customer_id' => $id]);
        }
        if (! $id && $this->is_credit) {
            $this->is_credit = false;
        }
    }

    public function updateReference(): void
    {
        if (! $this->sale_id) {
            return;
        }
        $sale = Sale::find($this->sale_id);
        if ($sale && $sale->isOpen()) {
            $sale->update(['reference' => $this->reference ?: null]);
        }
    }

    public function updatedPosDate(): void
    {
        if (! $this->is_admin || ! $this->sale_id) {
            return;
        }
        $sale = Sale::find($this->sale_id);
        if ($sale && $sale->isOpen()) {
            $sale->update(['pos_date' => $this->pos_date ?: now()->toDateString()]);
        }
    }

    public function updatedIsCredit(): void
    {
        if (! $this->sale_id) {
            return;
        }
        if ($this->is_credit && ! $this->customer_id) {
            $this->is_credit = false;
            $this->addError('customer_id', __('Customer is required for credit sales.'));
            return;
        }
        $sale = Sale::find($this->sale_id);
        if ($sale && $sale->isOpen()) {
            $sale->update(['is_credit' => (bool) $this->is_credit]);
        }
    }

    public function updatedInvoiceDiscountType(SaleService $service): void
    {
        $this->updateInvoiceDiscount($service);
    }

    public function updatedInvoiceDiscountValue(SaleService $service): void
    {
        $this->updateInvoiceDiscount($service);
    }

    public function updateInvoiceDiscount(SaleService $service): void
    {
        if (! $this->sale_id) {
            return;
        }
        $sale = Sale::find($this->sale_id);
        if (! $sale || ! $sale->isOpen()) {
            return;
        }
        try {
            $service->setGlobalDiscountValue($sale, $this->invoiceDiscountType, $this->invoiceDiscountValue);
        } catch (\InvalidArgumentException $e) {
            $this->addError('global_discount', __('Invalid discount.'));
        }
    }

    public function addItem(int $menuItemId, SaleService $service): void
    {
        $this->resetErrorBag();
        $userId = Auth::id();
        if (! $userId) {
            abort(403);
        }
        if (! $this->sale_id) {
            $this->ensureSale($service);
        }
        $sale = Sale::find($this->sale_id);
        if (! $sale) {
            $this->sale_id = null;
            return;
        }
        $item = MenuItem::find($menuItemId);
        if (! $item) {
            return;
        }
        try {
            $service->addMenuItem($sale, $item, qty: $this->qty, discountCents: 0);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
        }
    }

    public function searchAddItem(SaleService $service): void
    {
        $term = trim($this->search);
        if ($term === '') {
            return;
        }
        if (! $this->sale_id) {
            $this->ensureSale($service);
        }
        $item = MenuItem::query()
            ->active()
            ->availableInBranch($this->branch_id)
            ->where(function ($q) use ($term) {
                $q->where('code', $term)->orWhere('code', 'like', $term.'%');
            })
            ->first();
        if (! $item) {
            $item = MenuItem::query()
                ->active()
                ->availableInBranch($this->branch_id)
                ->search($term)
                ->first();
        }
        if ($item) {
            $this->addItem($item->id, $service);
        }
    }

    public function removeItem(int $saleItemId, SaleService $service): void
    {
        if (! $this->sale_id) {
            return;
        }
        $row = SaleItem::find($saleItemId);
        if (! $row || (int) $row->sale_id !== (int) $this->sale_id) {
            return;
        }
        $row->delete();
        if ($this->selected_item_id === $saleItemId) {
            $this->selected_item_id = null;
        }
        $service->recalc(Sale::findOrFail($this->sale_id));
    }

    public function selectItem(int $saleItemId): void
    {
        $this->selected_item_id = $saleItemId;
    }

    public function updateItemQty(int $saleItemId, string $qty, SaleService $service): void
    {
        $item = SaleItem::find($saleItemId);
        if (! $item || (int) $item->sale_id !== (int) $this->sale_id) {
            return;
        }
        try {
            $service->updateItemQty($item, $qty);
            $this->lineQty[$saleItemId] = $qty;
        } catch (\InvalidArgumentException $e) {
            $this->addError('qty', __('Invalid quantity.'));
        } catch (ValidationException $e) {
            $this->addError('qty', $e->getMessage());
        }
    }

    public function updateItemPrice(int $saleItemId, string $price, SaleService $service): void
    {
        $item = SaleItem::find($saleItemId);
        if (! $item || (int) $item->sale_id !== (int) $this->sale_id) {
            return;
        }
        try {
            $service->updateItemPrice($item, $price);
            $this->linePrice[$saleItemId] = $price;
        } catch (\InvalidArgumentException $e) {
            $this->addError('price', __('Invalid price.'));
        } catch (ValidationException $e) {
            $this->addError('price', $e->getMessage());
        }
    }

    public function updateItemDiscount(int $saleItemId, string $type, string $value, SaleService $service): void
    {
        $item = SaleItem::find($saleItemId);
        if (! $item || (int) $item->sale_id !== (int) $this->sale_id) {
            return;
        }
        try {
            $service->updateItemDiscount($item, $type, $value);
            $this->lineDiscountType[$saleItemId] = $type;
            $this->lineDiscountValue[$saleItemId] = $value;
        } catch (\InvalidArgumentException $e) {
            $this->addError('discount', __('Invalid discount.'));
        } catch (ValidationException $e) {
            $this->addError('discount', $e->getMessage());
        }
    }

    public function applyItemDiscount(int $saleItemId, SaleService $service): void
    {
        $type = $this->lineDiscountType[$saleItemId] ?? 'fixed';
        $value = $this->lineDiscountValue[$saleItemId] ?? '0';
        $this->updateItemDiscount($saleItemId, $type, $value, $service);
    }

    public function adjustItemQty(int $saleItemId, int $delta, SaleService $service): void
    {
        $item = SaleItem::find($saleItemId);
        if (! $item || (int) $item->sale_id !== (int) $this->sale_id) {
            return;
        }
        $current = MinorUnits::parseQtyMilli((string) $item->qty);
        $newQty = max(1, $current + ($delta * 1000));
        $service->updateItemQty($item, MinorUnits::format($newQty, 1000, false));
    }

    public function setGlobalDiscount(string $amount, SaleService $service): void
    {
        $this->resetErrorBag();
        if (! $this->sale_id) {
            return;
        }
        $sale = Sale::find($this->sale_id);
        if (! $sale || ! $sale->isOpen()) {
            return;
        }
        try {
            $cents = MinorUnits::parsePos($amount);
            $service->setGlobalDiscount($sale, max(0, $cents));
        } catch (\InvalidArgumentException $e) {
            $this->addError('global_discount', __('Invalid amount.'));
        }
    }

    public function holdCurrent(SaleService $service): void
    {
        $userId = Auth::id();
        if (! $userId || ! $this->sale_id) {
            return;
        }
        $sale = Sale::find($this->sale_id);
        if (! $sale || ! $sale->isOpen()) {
            return;
        }
        try {
            $service->hold($sale, $userId);
            $this->sale_id = null;
            session()->flash('status', __('Order held.'));
        } catch (ValidationException $e) {
            $this->addError('hold', $e->getMessage());
        }
    }

    public function recallSale(int $id, SaleService $service): void
    {
        $sale = Sale::find($id);
        if (! $sale || (int) $sale->branch_id !== (int) $this->branch_id || ! $sale->isHeld()) {
            return;
        }
        $userId = Auth::id();
        if (! $userId) {
            return;
        }
        $service->recall($sale, $userId);
        $this->sale_id = $sale->id;
        $this->showHeldModal = false;
        session()->flash('status', __('Order recalled.'));
    }

    public function quickPayCash(PosCheckoutService $checkout): void
    {
        $this->quickPay('cash', $checkout);
    }

    public function quickPayCard(PosCheckoutService $checkout): void
    {
        $this->quickPay('card', $checkout);
    }

    private function quickPay(string $method, PosCheckoutService $checkout): void
    {
        $this->resetErrorBag();
        $userId = Auth::id();
        if (! $userId || ! $this->sale_id) {
            return;
        }
        if ($this->is_credit) {
            $this->addError('sale_id', __('Credit sales must use the Credit action.'));
            return;
        }
        $sale = Sale::find($this->sale_id);
        if (! $sale) {
            $this->sale_id = null;
            return;
        }
        try {
            $closed = $checkout->quickPay($sale, $method, $userId);
            $this->sale_id = null;
            $this->redirectRoute('sales.receipt', $closed, navigate: true);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
        }
    }

    public function openPayModal(): void
    {
        $due = 0;
        if ($this->sale_id) {
            $sale = Sale::find($this->sale_id);
            if ($sale) {
                $due = (int) $sale->due_total_cents;
            }
        }
        $amount = $due > 0 ? MinorUnits::format($due, null, false) : '0.00';
        $this->payments = [['method' => 'cash', 'amount' => $amount]];
        $this->showPayModal = true;
    }

    public function addPaymentRow(): void
    {
        $this->payments[] = ['method' => 'card', 'amount' => '0.00'];
    }

    public function removePaymentRow(int $idx): void
    {
        unset($this->payments[$idx]);
        $this->payments = array_values($this->payments);
        if (count($this->payments) === 0) {
            $this->payments = [['method' => 'cash', 'amount' => '0.00']];
        }
    }

    public function checkout(PosCheckoutService $checkout): void
    {
        $this->resetErrorBag();
        $userId = Auth::id();
        if (! $userId || ! $this->sale_id) {
            $this->addError('sale_id', __('No active order.'));
            return;
        }
        $sale = Sale::find($this->sale_id);
        if (! $sale) {
            $this->sale_id = null;
            return;
        }
        $payload = [];
        foreach ($this->payments as $row) {
            $method = (string) ($row['method'] ?? 'cash');
            $amountStr = (string) ($row['amount'] ?? '0.00');
            try {
                $amountCents = MinorUnits::parsePos($amountStr);
            } catch (\InvalidArgumentException $e) {
                $this->addError('payments', __('Invalid payment amount.'));
                return;
            }
            if ($amountCents <= 0) {
                continue;
            }
            $payload[] = ['method' => $method, 'amount_cents' => $amountCents];
        }
        try {
            $closed = $checkout->checkout($sale, $payload, $userId);
            $this->showPayModal = false;
            $this->sale_id = null;
            $this->redirectRoute('sales.receipt', $closed, navigate: true);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
        }
    }

    public function checkoutCredit(PosCheckoutService $checkout): void
    {
        $this->resetErrorBag();
        $userId = Auth::id();
        if (! $userId || ! $this->sale_id) {
            $this->addError('sale_id', __('No active order.'));
            return;
        }
        if (! $this->customer_id) {
            $this->addError('customer_id', __('Customer is required for credit sales.'));
            return;
        }
        $sale = Sale::find($this->sale_id);
        if (! $sale) {
            $this->sale_id = null;
            return;
        }
        try {
            $closed = $checkout->checkoutCredit($sale, $userId);
            $this->sale_id = null;
            $this->redirectRoute('sales.receipt', $closed, navigate: true);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $m) {
                    $this->addError($field, $m);
                }
            }
        }
    }

    public function printKot(): void
    {
        if (! $this->sale_id) {
            return;
        }
        $this->redirectRoute('sales.kot', Sale::find($this->sale_id), navigate: true);
    }

    public function printReceipt(): void
    {
        if (! $this->sale_id) {
            return;
        }
        $sale = Sale::find($this->sale_id);
        if ($sale && $sale->isClosed()) {
            $this->redirectRoute('sales.receipt', $sale, navigate: true);
        }
    }

    public function formatMoney(?int $cents): string
    {
        return MinorUnits::format((int) ($cents ?? 0), null, true);
    }

    public function formatPercent(int $bps): string
    {
        $value = $bps / 100;
        $text = number_format($value, 2, '.', '');
        return rtrim(rtrim($text, '0'), '.');
    }

    public function updatedSaleId(): void
    {
        if ($this->sale_id) {
            $sale = Sale::find($this->sale_id);
            if ($sale) {
                $this->reference = (string) ($sale->reference ?? '');
                $this->customer_id = $sale->customer_id;
                $this->order_type = (string) ($sale->order_type ?? 'takeaway');
                $this->pos_date = $sale->pos_date?->toDateString() ?? now()->toDateString();
                $this->is_credit = (bool) ($sale->is_credit ?? false);
            }
        } else {
            $this->reference = '';
        }
    }

    public function updatedReference(): void
    {
        $this->updateReference();
    }
}; ?>

<style>
    .pos-fixed-header { height: 64px; }
    .pos-fixed-footer { height: 140px; }
    .pos-left-header { background: #2b3c4f; }
    .pos-tab-take { background: #2f80ed; }
    .pos-tab-dine { background: #eb5757; }
    .pos-cart-summary { background: #233445; }
    .pos-search { background: #f3f4f6; border-color: #e5e7eb; }
    .pos-pill { background: #e5e7eb; color: #374151; }
    .pos-pill-active { background: #2f80ed; color: #fff; }
    .pos-key { background: #ffffff; border: 1px solid #e5e7eb; }
    .pos-action-blue { background: #2f80ed; color: #fff; }
    .pos-action-green { background: #27ae60; color: #fff; }
    .pos-action-red { background: #eb5757; color: #fff; }
</style>

<div class="pos-container flex h-screen flex-col pt-16 pb-[140px]" x-data="posHotkeys()" x-init="init()">
    <div class="fixed top-0 left-0 right-0 z-50 flex pos-fixed-header border-b border-neutral-200 bg-white">
        <div class="flex w-1/4 min-w-[280px] items-center pos-left-header text-white">
            <div class="flex w-full items-center">
                <div class="flex h-full items-center px-4 text-sm font-semibold">New Order <span class="ml-2 text-xs opacity-70">[F2]</span></div>
                <button type="button"
                    class="pos-tab-take h-full px-4 text-sm font-semibold text-white"
                    wire:click="setOrderType('takeaway')">
                    TakeAway ({{ $takeawayOpen + $takeawayHeld }})
                </button>
                <button type="button"
                    class="pos-tab-dine h-full px-4 text-sm font-semibold text-white"
                    wire:click="setOrderType('dine_in')">
                    Dine In ({{ $dineInOpen + $dineInHeld }})
                </button>
            </div>
        </div>
        <div class="flex w-3/4 items-center justify-between px-4">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-sky-600 text-xs font-semibold text-white">C</span>
                <button type="button" class="text-sm font-medium text-neutral-700" wire:click="$set('showCustomerModal', true)">
                    {{ $customer_id && Schema::hasTable('customers') ? (Customer::find($customer_id)?->name ?? __('Cash Customer')) : __('Cash Customer') }} [F9]
                </button>
            </div>
            <div class="flex items-center gap-2 text-sm text-neutral-500">
                <span class="rounded-full border border-neutral-300 p-1">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h18v18H3z" /></svg>
                </span>
                <span class="font-mono">#{{ $sale_id ? $sale_id : '000000' }}</span>
            </div>
        </div>
    </div>

    <div class="flex flex-1 min-h-0">
        {{-- Left order pane --}}
        <div class="flex w-1/4 min-w-[280px] flex-col border-r border-neutral-200 bg-white">
            <div class="border-b border-neutral-200 p-3">
                <div class="flex items-center gap-2 rounded-md border pos-search px-2 py-2">
                    <svg class="h-4 w-4 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.3-4.3m1.8-5.2a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    <input class="w-full bg-transparent text-sm text-neutral-700 outline-none"
                        wire:model.live.debounce.200ms="search" wire:keydown.enter="searchAddItem"
                        placeholder="Search for items [F6]" />
                </div>
                @if ($sale)
                    <div class="mt-3 rounded-md pos-cart-summary p-3 text-white">
                        <div class="flex items-center justify-between text-sm">
                            <span>{{ $order_type === 'takeaway' ? 'Take Away' : 'Dine In' }}</span>
                            <span>Total {{ config('pos.currency') }} {{ $this->formatMoney($sale->total_cents ?? 0) }}</span>
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <input class="w-full rounded border border-white/20 bg-white/10 px-2 py-1 text-xs text-white"
                                wire:model.live="reference" placeholder="Ref:" />
                        </div>
                        <div class="mt-3 flex items-center gap-3">
                            <div class="rounded bg-white/10 px-3 py-2 text-sm">{{ config('pos.currency') }}</div>
                            <div class="text-3xl font-semibold">{{ $this->formatMoney($sale->total_cents ?? 0) }}</div>
                            <div class="text-sm text-white/70">{{ $sale->items->count() }} items</div>
                        </div>
                    </div>
                @endif
            </div>
            <div class="flex-1 overflow-y-auto p-2 pb-24">
                @if (! $sale)
                    <p class="py-8 text-center text-sm text-neutral-500 dark:text-neutral-400">{{ __('No active order. Click New Order or recall a held order.') }}</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($sale->items as $row)
                            <li class="flex items-center justify-between gap-2 rounded-lg border border-neutral-200 bg-white p-2"
                                wire:click="selectItem({{ $row->id }})">
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium text-neutral-800">{{ $row->name_snapshot }}</div>
                                    <div class="text-xs text-neutral-500">{{ $row->qty }} × {{ \App\Support\Money\MinorUnits::format((int) $row->unit_price_cents, null, false) }}</div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-semibold text-neutral-800">{{ $this->formatMoney($row->line_total_cents) }}</span>
                                    <button type="button" class="text-neutral-400" wire:click.stop="removeItem({{ $row->id }})">×</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if ($selected_item_id && $sale->items->firstWhere('id', $selected_item_id))
                        @php $selected = $sale->items->firstWhere('id', $selected_item_id); @endphp
                        <div class="mt-3 rounded-md border border-neutral-200 bg-white p-2 text-xs">
                            <div class="mb-2 font-medium text-neutral-700">{{ __('Edit item') }}: {{ $selected->name_snapshot }}</div>
                            <div class="grid grid-cols-3 gap-2">
                                <div class="flex items-center gap-1">
                                    <button type="button" class="rounded border border-neutral-200 px-1" wire:click="adjustItemQty({{ $selected->id }}, -1)">-</button>
                                    <input type="text" class="w-16 rounded border border-neutral-200 px-1 py-0.5"
                                        wire:model.lazy="lineQty.{{ $selected->id }}"
                                        value="{{ $lineQty[$selected->id] ?? $selected->qty }}"
                                        wire:blur="updateItemQty({{ $selected->id }}, $event.target.value)" />
                                    <button type="button" class="rounded border border-neutral-200 px-1" wire:click="adjustItemQty({{ $selected->id }}, 1)">+</button>
                                </div>
                                <div class="flex items-center gap-1">
                                    <span>{{ __('Price') }}</span>
                                    <input type="text" class="w-20 rounded border border-neutral-200 px-1 py-0.5"
                                        wire:model.lazy="linePrice.{{ $selected->id }}"
                                        value="{{ $linePrice[$selected->id] ?? \App\Support\Money\MinorUnits::format((int) $selected->unit_price_cents, null, false) }}"
                                        wire:blur="updateItemPrice({{ $selected->id }}, $event.target.value)" />
                                </div>
                                <div class="flex items-center gap-1">
                                    <select class="rounded border border-neutral-200 px-1 py-0.5"
                                        wire:model.live="lineDiscountType.{{ $selected->id }}">
                                        <option value="fixed">{{ __('Fixed') }}</option>
                                        <option value="percent">{{ __('%') }}</option>
                                    </select>
                                    <input type="text" class="w-16 rounded border border-neutral-200 px-1 py-0.5"
                                        wire:model.lazy="lineDiscountValue.{{ $selected->id }}"
                                        value="{{ $lineDiscountValue[$selected->id] ?? '0' }}"
                                        wire:blur="applyItemDiscount({{ $selected->id }})" />
                                </div>
                            </div>
                        </div>
                    @endif
                    @php $sale = $sale->fresh(); @endphp
                    <div class="mt-3 border-t border-neutral-200 pt-3 dark:border-neutral-700">
                        <div class="flex justify-between text-sm text-neutral-600 dark:text-neutral-300">
                            <span>{{ __('Subtotal') }}</span>
                            <span>{{ $this->formatMoney($sale->subtotal_cents) }}</span>
                        </div>
                        <div class="flex justify-between text-sm text-neutral-600 dark:text-neutral-300">
                            <span>{{ __('Discount') }}</span>
                            <span>-{{ $this->formatMoney($sale->discount_total_cents) }}</span>
                        </div>
                        <div class="flex items-center justify-between text-sm text-neutral-600 dark:text-neutral-300">
                            <span>{{ __('Invoice discount') }}</span>
                            <div class="flex items-center gap-1">
                                <select class="rounded border border-neutral-200 px-1 py-0.5 text-xs dark:border-neutral-700 dark:bg-neutral-800"
                                    wire:model.live="invoiceDiscountType">
                                    <option value="fixed">{{ __('Fixed') }}</option>
                                    <option value="percent">{{ __('%') }}</option>
                                </select>
                                <input type="text"
                                    class="w-16 rounded border border-neutral-200 px-1 py-0.5 text-xs dark:border-neutral-700 dark:bg-neutral-800"
                                    wire:model.live="invoiceDiscountValue" />
                            </div>
                        </div>
                        @if ((int)($sale->global_discount_cents ?? 0) > 0)
                            <div class="flex justify-between text-sm text-neutral-600 dark:text-neutral-300">
                                <span>{{ __('Global discount') }}</span>
                                <span>-{{ $this->formatMoney($sale->global_discount_cents) }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between text-sm text-neutral-600 dark:text-neutral-300">
                            <span>{{ __('Tax') }}</span>
                            <span>{{ $this->formatMoney($sale->tax_total_cents) }}</span>
                        </div>
                        <div class="flex justify-between border-t border-neutral-200 pt-2 text-base font-semibold dark:border-neutral-700">
                            <span>{{ __('Total') }}</span>
                            <span>{{ $this->formatMoney($sale->total_cents) }}</span>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-sm text-neutral-600 dark:text-neutral-300">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" wire:model.live="is_credit" @if (! $customer_id) disabled @endif />
                                <span>{{ __('Credit sale') }}</span>
                            </label>
                            @if (! $customer_id)
                                <span class="text-xs text-amber-600">{{ __('Select customer') }}</span>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
            <div class="fixed bottom-0 left-0 z-50 w-full pos-fixed-footer border-t border-neutral-200 bg-white">
                <div class="flex h-full">
                    <div class="flex w-1/4 min-w-[280px] items-center justify-between px-3">
                        <button type="button" class="pos-action-red flex h-12 w-12 items-center justify-center rounded-md" wire:click="newSale">X</button>
                        <button type="button" class="pos-action-blue flex h-12 w-16 items-center justify-center rounded-md" wire:click="printKot">KOT</button>
                        <button type="button" class="pos-action-blue flex h-12 w-12 items-center justify-center rounded-md" wire:click="printReceipt">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9V4h12v5M6 18h12v-5H6v5z" /></svg>
                        </button>
                        <div class="flex h-12 w-12 items-center justify-center rounded-md bg-neutral-800 text-white">0</div>
                        <button type="button" class="pos-action-green flex h-12 w-12 items-center justify-center rounded-md" wire:click="$set('showHeldModal', true)">></button>
                    </div>
                    <div class="flex w-3/4 items-center justify-between px-4">
                        <div class="grid grid-cols-5 gap-2 text-sm">
                            <button class="pos-key h-10 w-16">Split</button>
                            <button class="pos-key h-10 w-16">Merge</button>
                            <button class="pos-key h-10 w-16">7</button>
                            <button class="pos-key h-10 w-16">8</button>
                            <button class="pos-key h-10 w-16">9</button>
                            <button class="pos-key h-10 w-16" wire:click="quickPayCash">Cash</button>
                            <button class="pos-key h-10 w-16" wire:click="quickPayCard">Card</button>
                            <button class="pos-key h-10 w-16">4</button>
                            <button class="pos-key h-10 w-16">5</button>
                            <button class="pos-key h-10 w-16">6</button>
                            <button class="pos-key h-10 w-16" wire:click="checkoutCredit">Credit</button>
                            <button class="pos-key h-10 w-16">Complement</button>
                            <button class="pos-key h-10 w-16">1</button>
                            <button class="pos-key h-10 w-16">2</button>
                            <button class="pos-key h-10 w-16">3</button>
                            <button class="pos-key h-10 w-16" wire:click="openPayModal">Mixed Pay</button>
                            <button class="pos-key h-10 w-16">Return</button>
                            <button class="pos-key h-10 w-16">0</button>
                            <button class="pos-key h-10 w-16">.</button>
                            <button class="pos-key h-10 w-16">C</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Right product pane --}}
        <div class="flex w-3/4 flex-col min-w-0 bg-white">
            <div class="flex items-center justify-between border-b border-neutral-200 p-2 dark:border-neutral-700">
                <div class="flex items-center gap-2">
                    <button type="button" class="flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm text-neutral-700"
                        wire:click="$set('showCustomerModal', true)">
                        @if ($customer_id && Schema::hasTable('customers'))
                            @php $cust = Customer::find($customer_id); @endphp
                            {{ $cust ? $cust->name : __('Cash Customer') }}
                        @else
                            {{ __('Cash Customer') }} <span class="text-xs opacity-70">[F9]</span>
                        @endif
                    </button>
                </div>
                <span class="text-sm font-mono text-neutral-500 dark:text-neutral-400">#{{ $sale_id ? $sale_id : '000000' }}</span>
            </div>
            <div class="border-b border-neutral-200 px-3 py-2">
                <div class="flex items-center gap-2">
                    <button class="text-neutral-400">&lsaquo;</button>
                    <div class="flex gap-2 overflow-x-auto pb-1">
                        @foreach ($categories as $cat)
                            <button type="button"
                                class="shrink-0 rounded-full px-4 py-1.5 text-sm font-medium {{ $category_id === $cat->id ? 'pos-pill-active' : 'pos-pill' }}"
                                wire:click="setCategory({{ $cat->id }})">
                                {{ $cat->name }}
                                <span class="ml-2 rounded-full bg-white/20 px-2 text-xs">{{ $cat->count }}</span>
                            </button>
                        @endforeach
                    </div>
                    <button class="text-neutral-400">&rsaquo;</button>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto p-3">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                    @foreach ($menuItems as $mi)
                        <button type="button"
                            class="flex flex-col items-center justify-center rounded-lg border border-neutral-200 bg-white p-4 transition hover:border-neutral-400"
                            wire:click="addItem({{ $mi->id }})">
                            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-lg bg-neutral-100">
                                <svg class="h-6 w-6 text-neutral-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 6v12m6-12v12M5 6h14M5 18h14" /></svg>
                            </div>
                            <span class="text-center text-sm font-medium text-neutral-700 line-clamp-2">{{ $mi->name }}</span>
                            @if ($mi->code)
                                <span class="mt-0.5 text-xs text-neutral-400">{{ $mi->code }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
                @if ($menuItems->isEmpty())
                    <p class="py-8 text-center text-sm text-neutral-500 dark:text-neutral-400">{{ __('No items in this category.') }}</p>
                @endif
            </div>
        </div>
    </div>

    @if ($showPayModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-lg rounded-lg border border-neutral-200 bg-white p-4 shadow-lg dark:border-neutral-700 dark:bg-neutral-900 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">{{ __('Checkout') }}</h2>
                    <flux:button type="button" variant="ghost" wire:click="$set('showPayModal', false)">{{ __('Close') }}</flux:button>
                </div>
                @foreach ($payments as $idx => $row)
                    <div class="grid grid-cols-5 gap-2">
                        <select wire:model="payments.{{ $idx }}.method" class="col-span-2 rounded-md border border-neutral-200 px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800">
                            <option value="cash">{{ __('Cash') }}</option>
                            <option value="card">{{ __('Card') }}</option>
                            <option value="online">{{ __('Online') }}</option>
                            <option value="bank">{{ __('Bank') }}</option>
                            <option value="voucher">{{ __('Voucher') }}</option>
                        </select>
                        <flux:input wire:model="payments.{{ $idx }}.amount" class="col-span-2 !mb-0" />
                        <flux:button size="xs" type="button" variant="ghost" wire:click="removePaymentRow({{ $idx }})">{{ __('×') }}</flux:button>
                    </div>
                @endforeach
                <div class="flex justify-between">
                    <flux:button type="button" variant="outline" wire:click="addPaymentRow">{{ __('Add payment') }}</flux:button>
                    <flux:button type="button" variant="primary" wire:click="checkout">{{ __('Confirm') }}</flux:button>
                </div>
            </div>
        </div>
    @endif

    @if ($showHeldModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" wire:click.self="$set('showHeldModal', false)">
            <div class="w-full max-w-md rounded-lg border border-neutral-200 bg-white p-4 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="text-lg font-semibold mb-3">{{ __('Held Orders') }}</h2>
                <ul class="space-y-2 max-h-64 overflow-y-auto">
                    @forelse ($heldList as $held)
                        <li class="flex items-center justify-between rounded-lg border border-neutral-200 p-2 dark:border-neutral-700">
                            <span>#{{ $held->id }} {{ $held->order_type ?? 'takeaway' }} {{ $this->formatMoney($held->total_cents) }}</span>
                            <flux:button size="xs" type="button" wire:click="recallSale({{ $held->id }})">{{ __('Recall') }}</flux:button>
                        </li>
                    @empty
                        <li class="text-sm text-neutral-500">{{ __('No held orders.') }}</li>
                    @endforelse
                </ul>
                <flux:button type="button" variant="ghost" class="mt-3 w-full" wire:click="$set('showHeldModal', false)">{{ __('Close') }}</flux:button>
            </div>
        </div>
    @endif

    @if ($showCustomerModal)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" wire:click.self="$set('showCustomerModal', false)">
            <div class="w-full max-w-md rounded-lg border border-neutral-200 bg-white p-4 shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                <h2 class="text-lg font-semibold mb-3">{{ __('Select Customer') }} [F9]</h2>
                <flux:input wire:model.live.debounce.200ms="customerSearch" placeholder="{{ __('Search name, email, phone') }}" class="!mb-3" />
                <button type="button" class="w-full rounded-lg border p-2 text-left text-sm {{ !$customer_id ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-200 dark:border-neutral-700' }}" wire:click="setCustomer(null)">
                    {{ __('Cash Customer') }}
                </button>
                <ul class="mt-2 max-h-48 overflow-y-auto space-y-1">
                    @foreach ($customers as $c)
                        <li>
                            <button type="button" class="w-full rounded-lg border border-neutral-200 p-2 text-left text-sm hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800 {{ $customer_id == $c->id ? 'ring-2 ring-primary-500' : '' }}" wire:click="setCustomer({{ $c->id }})">
                                {{ $c->name }} @if($c->email) ({{ $c->email }}) @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
                <flux:button type="button" variant="ghost" class="mt-3 w-full" wire:click="$set('showCustomerModal', false)">{{ __('Close') }}</flux:button>
            </div>
        </div>
    @endif

    @if (session('status'))
        <div class="fixed bottom-20 left-1/2 z-50 -translate-x-1/2 rounded-lg bg-emerald-600 px-4 py-2 text-sm text-white shadow-lg">
            {{ session('status') }}
        </div>
    @endif
    @error('sale_id') <p class="fixed bottom-20 left-4 z-50 text-sm text-rose-600">{{ $message }}</p> @enderror
    @error('payments') <p class="fixed bottom-20 left-4 z-50 text-sm text-rose-600">{{ $message }}</p> @enderror
    @error('price') <p class="fixed bottom-16 left-4 z-50 text-sm text-rose-600">{{ $message }}</p> @enderror
    @error('discount') <p class="fixed bottom-12 left-4 z-50 text-sm text-rose-600">{{ $message }}</p> @enderror
    @error('qty') <p class="fixed bottom-8 left-4 z-50 text-sm text-rose-600">{{ $message }}</p> @enderror
    @error('global_discount') <p class="fixed bottom-4 left-4 z-50 text-sm text-rose-600">{{ $message }}</p> @enderror
    @error('customer_id') <p class="fixed bottom-4 left-4 z-50 text-sm text-rose-600">{{ $message }}</p> @enderror
</div>

<script>
function posHotkeys() {
    return {
        init() {
            window.addEventListener('keydown', (e) => this.handleKey(e));
        },
        handleKey(e) {
            if (e.target.closest('input, select, textarea')) return;
            const wire = this.$wire;
            if (!wire) return;
            if (e.key === 'F2') { e.preventDefault(); wire.call('newSale'); return; }
            if (e.key === 'F6') { e.preventDefault(); document.querySelector('input[placeholder*="Search"]')?.focus(); return; }
            if (e.key === 'F9') { e.preventDefault(); wire.set('showCustomerModal', true); return; }
            if (e.key === 'F1') { e.preventDefault(); wire.call('quickPayCash'); return; }
            if (e.key === 'F12') { e.preventDefault(); wire.call('quickPayCard'); return; }
            if (e.key === 'F8') { e.preventDefault(); wire.call('printKot'); return; }
            if (e.key === 'F3') { e.preventDefault(); wire.call('newSale'); return; }
            if (e.altKey && e.key === 'k') { e.preventDefault(); wire.set('showHeldModal', true); return; }
            if (e.altKey && e.key === 'p') { e.preventDefault(); wire.call('printReceipt'); return; }
        }
    };
}
</script>

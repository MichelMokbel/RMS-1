<?php

use App\Models\InventoryItem;
use App\Services\Inventory\InventoryItemFormQueryService;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\InventoryTransactionQueryService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public ?int $item_id = null;
    public ?int $branch_id = null;
    public string $transaction_type = 'in';
    public float $quantity = 1.0;
    public ?float $unit_cost = null;
    public ?string $notes = null;
    public ?string $transaction_date = null;

    protected $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $this->branch_id = (int) config('inventory.default_branch_id', 1);
        $this->transaction_date = now()->format('Y-m-d\TH:i');
    }

    public function with(InventoryItemFormQueryService $formQuery, InventoryTransactionQueryService $queryService): array
    {
        return [
            'items' => InventoryItem::query()->with('category.parent.parent.parent')->orderBy('name')->get(),
            'branches' => $formQuery->branches(),
            'transactions' => $queryService->query([
                'branch_id' => $this->branch_id,
            ])->paginate(20),
        ];
    }

    public function save(InventoryStockService $stockService): void
    {
        $data = $this->validate([
            'item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'branch_id' => ['required', 'integer', 'min:1'],
            'transaction_type' => ['required', 'in:in,out,adjust'],
            'quantity' => ['required', 'numeric'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'transaction_date' => ['required', 'date'],
        ]);

        $item = InventoryItem::query()->findOrFail((int) $data['item_id']);

        $stockService->postTransaction(
            $item,
            $data['transaction_type'],
            (float) $data['quantity'],
            $data['notes'] ?? null,
            (int) (Auth::id() ?? 0),
            (int) $data['branch_id'],
            isset($data['unit_cost']) ? (float) $data['unit_cost'] : null,
            'manual',
            null,
            $data['transaction_date']
        );

        $this->reset(['item_id', 'transaction_type', 'quantity', 'unit_cost', 'notes']);
        $this->transaction_type = 'in';
        $this->quantity = 1.0;
        $this->transaction_date = now()->format('Y-m-d\TH:i');
        session()->flash('status', __('Transaction recorded.'));
    }
}; ?>

<div class="app-page space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{{ __('Inventory Transactions') }}</h1>
        <div class="flex gap-2">
            <flux:button :href="route('inventory.index')" wire:navigate variant="ghost">{{ __('Back to Inventory') }}</flux:button>
            <flux:button :href="route('reports.inventory-transactions')" wire:navigate variant="ghost">{{ __('Open Report') }}</flux:button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
        <form wire:submit="save" class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Item') }}</label>
                <select wire:model="item_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="">{{ __('Select item') }}</option>
                    @foreach ($items as $item)
                        <option value="{{ $item->id }}">{{ $item->item_code }} {{ $item->name }}{{ $item->categoryLabel() ? ' ['.$item->categoryLabel().']' : '' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Branch') }}</label>
                <select wire:model="branch_id" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-sm font-medium text-neutral-700 dark:text-neutral-200">{{ __('Type') }}</label>
                <select wire:model="transaction_type" class="mt-1 w-full rounded-md border border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-50">
                    <option value="in">{{ __('In') }}</option>
                    <option value="out">{{ __('Out') }}</option>
                    <option value="adjust">{{ __('Adjust') }}</option>
                </select>
            </div>
            <flux:input wire:model="quantity" type="number" step="0.001" :label="__('Quantity')" />
            <flux:input wire:model="unit_cost" type="number" step="0.0001" min="0" :label="__('Unit Cost (optional)')" />
            <flux:input wire:model="transaction_date" type="datetime-local" :label="__('Transaction Date')" />
            <div class="md:col-span-3">
                <flux:textarea wire:model="notes" :label="__('Description / Notes')" rows="2" />
            </div>
            <div class="md:col-span-3 flex justify-end">
                <flux:button type="submit" variant="primary">{{ __('Record Transaction') }}</flux:button>
            </div>
        </form>
    </div>

    <div class="app-table-shell">
        <table class="w-full min-w-full table-auto divide-y divide-neutral-200 dark:divide-neutral-800">
            <thead class="bg-neutral-50 dark:bg-neutral-800/90">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Item') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Type') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Qty') }}</th>
                    <th class="px-3 py-2 text-right text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Unit Cost') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Reference') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('User') }}</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase text-neutral-700 dark:text-neutral-100">{{ __('Notes') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                @forelse ($transactions as $transaction)
                    <tr class="hover:bg-neutral-50 dark:hover:bg-neutral-800/70">
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $transaction->transaction_date?->format('Y-m-d H:i') }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100">{{ $transaction->item?->item_code }} {{ $transaction->item?->name }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ ucfirst($transaction->transaction_type) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format((float) $transaction->delta(), 3) }}</td>
                        <td class="px-3 py-2 text-sm text-right text-neutral-700 dark:text-neutral-200">{{ number_format((float) ($transaction->unit_cost ?? 0), 4) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ trim(($transaction->reference_type ?? '').' '.($transaction->reference_id ?? '')) }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $transaction->user?->username ?? $transaction->user?->email ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-neutral-700 dark:text-neutral-200">{{ $transaction->notes ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-neutral-600 dark:text-neutral-300">{{ __('No transactions found.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $transactions->links() }}</div>
</div>

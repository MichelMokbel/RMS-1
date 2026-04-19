<?php

namespace App\Services\PastryOrders;

use App\Models\MenuItem;
use App\Models\PastryOrder;
use App\Models\PastryOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PastryOrderCreateService
{
    public function __construct(
        protected PastryOrderNumberService $numbers,
        protected PastryOrderTotalsService $totals,
        protected PastryOrderImageService $images,
    ) {}

    /**
     * @param  array  $data
     * @param  \Illuminate\Http\UploadedFile[]  $uploadedImages  Zero or more uploaded image files
     * @param  int|null  $actorId
     */
    public function create(array $data, array $uploadedImages, ?int $actorId): PastryOrder
    {
        $items = collect($data['items'] ?? [])
            ->filter(fn ($row) => ! empty($row['menu_item_id']))
            ->values();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => __('At least one item is required.'),
            ]);
        }

        $menuItems     = $this->loadMenuItems($items);
        $subtotal      = $this->computeSubtotal($items, $menuItems);
        $orderDiscount = (float) ($data['order_discount_amount'] ?? 0);

        if ($orderDiscount > $subtotal + 0.0001) {
            throw ValidationException::withMessages([
                'order_discount_amount' => __('Order discount cannot exceed subtotal.'),
            ]);
        }

        return DB::transaction(function () use ($data, $items, $menuItems, $uploadedImages, $actorId) {
            $orderNumber = $this->numbers->generate();

            $order = PastryOrder::create([
                'order_number'              => $orderNumber,
                'sales_order_number'        => $data['sales_order_number'] ?? null,
                'branch_id'                 => $data['branch_id'] ?? null,
                'status'                    => $data['status'] ?? 'Draft',
                'type'                      => $data['type'] ?? 'Pickup',
                'customer_id'               => $data['customer_id'] ?? null,
                'customer_name_snapshot'    => $data['customer_name_snapshot'],
                'customer_phone_snapshot'   => $data['customer_phone_snapshot'] ?? null,
                'delivery_address_snapshot' => $data['delivery_address_snapshot'] ?? null,
                'scheduled_date'            => $data['scheduled_date'] ?? null,
                'scheduled_time'            => $data['scheduled_time'] ?? null,
                'notes'                     => $data['notes'] ?? null,
                'order_discount_amount'     => (float) ($data['order_discount_amount'] ?? 0),
                'total_before_tax'          => 0,
                'tax_amount'                => 0,
                'total_amount'              => 0,
                'created_by'                => $actorId,
                'created_at'                => now(),
            ]);

            // Store uploaded images
            if (! empty($uploadedImages)) {
                $this->images->storeMultiple($uploadedImages, $order, 0);
            }

            foreach ($items as $idx => $row) {
                $menuItem  = $menuItems->get((int) $row['menu_item_id']);
                $qty       = (float) ($row['quantity'] ?? 1);
                $price     = (float) ($menuItem?->selling_price_per_unit ?? 0);
                $discount  = (float) ($row['discount_amount'] ?? 0);
                $lineTotal = max(0, round(($qty * $price) - $discount, 3));

                PastryOrderItem::create([
                    'pastry_order_id'      => $order->id,
                    'menu_item_id'         => (int) $row['menu_item_id'],
                    'description_snapshot' => trim(($menuItem?->code ?? '').' '.($menuItem?->name ?? '')),
                    'quantity'             => $qty,
                    'unit_price'           => $price,
                    'discount_amount'      => $discount,
                    'line_total'           => $lineTotal,
                    'status'               => 'Pending',
                    'sort_order'           => $row['sort_order'] ?? $idx,
                ]);
            }

            $this->totals->recalc($order);

            return $order->fresh(['items', 'images']);
        });
    }

    private function loadMenuItems(Collection $items): Collection
    {
        $ids = $items->pluck('menu_item_id')->map(fn ($id) => (int) $id)->all();

        $menuItems = MenuItem::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($menuItems->count() !== $items->count()) {
            throw ValidationException::withMessages([
                'items' => __('Some menu items could not be found.'),
            ]);
        }

        return $menuItems;
    }

    private function computeSubtotal(Collection $items, Collection $menuItems): float
    {
        return (float) $items->reduce(function (float $carry, array $row) use ($menuItems): float {
            $qty      = (float) ($row['quantity'] ?? 1);
            $menuItem = $menuItems->get((int) ($row['menu_item_id'] ?? 0));
            $price    = (float) ($menuItem?->selling_price_per_unit ?? 0);
            $discount = (float) ($row['discount_amount'] ?? 0);

            return $carry + max(0, ($qty * $price) - $discount);
        }, 0.0);
    }
}

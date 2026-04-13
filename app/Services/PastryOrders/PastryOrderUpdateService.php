<?php

namespace App\Services\PastryOrders;

use App\Models\MenuItem;
use App\Models\PastryOrder;
use App\Models\PastryOrderImage;
use App\Models\PastryOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PastryOrderUpdateService
{
    public function __construct(
        protected PastryOrderTotalsService $totals,
        protected PastryOrderImageService $images,
    ) {}

    /**
     * @param  array  $data
     * @param  \Illuminate\Http\UploadedFile[]  $newImages         Newly uploaded image files to append
     * @param  int[]  $removeImageIds                               IDs of PastryOrderImage rows to delete
     */
    public function update(PastryOrder $order, array $data, array $newImages, array $removeImageIds): PastryOrder
    {
        if ($order->isInvoiced()) {
            throw ValidationException::withMessages([
                'order' => __('Invoiced pastry orders cannot be edited.'),
            ]);
        }

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

        return DB::transaction(function () use ($order, $data, $items, $menuItems, $newImages, $removeImageIds) {
            // Remove individually deleted images
            if (! empty($removeImageIds)) {
                $toDelete = PastryOrderImage::where('pastry_order_id', $order->id)
                    ->whereIn('id', $removeImageIds)
                    ->get();

                foreach ($toDelete as $img) {
                    $this->images->delete($img->image_path);
                    $img->delete();
                }
            }

            // Append newly uploaded images
            if (! empty($newImages)) {
                $maxSort = $order->images()->max('sort_order') ?? -1;
                $this->images->storeMultiple($newImages, $order, $maxSort + 1);
            }

            $order->update([
                'branch_id'                 => $data['branch_id'] ?? $order->branch_id,
                'status'                    => $data['status'],
                'type'                      => $data['type'],
                'customer_id'               => $data['customer_id'] ?? null,
                'customer_name_snapshot'    => $data['customer_name_snapshot'],
                'customer_phone_snapshot'   => $data['customer_phone_snapshot'] ?? null,
                'delivery_address_snapshot' => $data['delivery_address_snapshot'] ?? null,
                'scheduled_date'            => $data['scheduled_date'] ?? null,
                'scheduled_time'            => $data['scheduled_time'] ?? null,
                'notes'                     => $data['notes'] ?? null,
                'order_discount_amount'     => (float) ($data['order_discount_amount'] ?? 0),
            ]);

            $order->items()->delete();

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

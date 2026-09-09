<?php

namespace App\Services\Storefront;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Services\AR\ArInvoiceService;
use App\Services\Orders\OrderNumberService;
use App\Services\Payments\PaymentCheckoutException;

class StorefrontMenuOrderCreationService
{
    public function __construct(
        private readonly OrderNumberService $numbers,
        private readonly ArInvoiceService $invoices,
    ) {}

    /** @return array{order:Order,invoice:\App\Models\ArInvoice} */
    public function create(
        PaymentCheckoutAttempt $attempt,
        PaymentCheckoutTarget $target,
        int $customerId,
        int $actorId,
        string $invoiceDate,
    ): array {
        $snapshot = is_array($target->item_snapshot) ? $target->item_snapshot : [];
        if (($snapshot['schema'] ?? null) !== 'menu-order-target-v1') {
            throw new PaymentCheckoutException('TARGET_SNAPSHOT_INVALID', 503, __('The menu checkout target is invalid.'));
        }

        $items = $target->items()->orderBy('sequence')->lockForUpdate()->get();
        if ($items->isEmpty()
            || (int) $items->sum('line_total_cents') !== (int) $target->expected_amount_cents) {
            throw new PaymentCheckoutException('TARGET_TOTAL_MISMATCH', 503, __('The retained menu order lines are inconsistent.'));
        }

        $order = Order::query()->create([
            'order_number' => $this->numbers->generate(),
            'branch_id' => $attempt->branch_id,
            'source' => 'Website',
            'is_daily_dish' => false,
            'daily_dish_portion_type' => null,
            'daily_dish_portion_quantity' => null,
            'type' => 'Delivery',
            'status' => 'Draft',
            'customer_id' => $customerId,
            'user_id' => $attempt->portal_user_id,
            'customer_name_snapshot' => $attempt->customer_snapshot['full_name'] ?? null,
            'customer_phone_snapshot' => $attempt->customer_snapshot['phone'] ?? null,
            'customer_email_snapshot' => $attempt->customer_snapshot['email'] ?? null,
            'delivery_address_snapshot' => $attempt->customer_snapshot['address'] ?? null,
            'scheduled_date' => $target->service_date,
            'scheduled_time' => null,
            'notes' => $snapshot['note'] ?? null,
            'order_discount_amount' => '0.000',
            'total_before_tax' => $this->decimalCents((int) $target->expected_amount_cents),
            'tax_amount' => '0.000',
            'total_amount' => $this->decimalCents((int) $target->expected_amount_cents),
            'created_by' => $actorId,
        ]);

        $invoiceItems = [];
        foreach ($items as $item) {
            $description = trim((string) $item->title);
            if ($description === '' || (int) $item->menu_item_id <= 0) {
                throw new PaymentCheckoutException('TARGET_SNAPSHOT_INVALID', 503, __('The retained menu order line is invalid.'));
            }
            OrderItem::query()->create([
                'order_id' => $order->id,
                'menu_item_id' => (int) $item->menu_item_id,
                'description_snapshot' => $description,
                'quantity' => (string) $item->quantity,
                'unit_price' => $this->decimalCents((int) $item->unit_price_cents),
                'discount_amount' => '0.000',
                'line_total' => $this->decimalCents((int) $item->line_total_cents),
                'status' => 'Pending',
                'sort_order' => (int) $item->sequence - 1,
                'role' => $item->line_role === 'checkout_add_on' ? 'checkout_add_on' : null,
            ]);
            $invoiceItems[] = [
                'menu_item_id' => (int) $item->menu_item_id,
                'title' => $description,
                'canonical_name' => $this->canonicalName($attempt, (int) $item->menu_item_id, $description),
                'description' => $item->description,
                'unit' => (string) $item->unit,
                'quantity' => (string) $item->quantity,
                'unit_price_cents' => (int) $item->unit_price_cents,
                'line_total_cents' => (int) $item->line_total_cents,
            ];
        }

        $invoice = $this->invoices->createFromOrderSnapshot(
            $order,
            $invoiceItems,
            (int) $target->expected_amount_cents,
            $actorId,
            __('SkipCash checkout :reference', ['reference' => $attempt->reference]),
            $invoiceDate,
        );
        $invoice = $this->invoices->issue($invoice, $actorId, false);

        return ['order' => $order, 'invoice' => $invoice];
    }

    private function canonicalName(PaymentCheckoutAttempt $attempt, int $menuItemId, string $fallback): string
    {
        foreach ((array) ($attempt->cart_snapshot['items'] ?? []) as $item) {
            if ((int) ($item['menu_item_id'] ?? 0) === $menuItemId) {
                return (string) ($item['canonical_name'] ?? $fallback);
            }
        }

        return $fallback;
    }

    private function decimalCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT).'0';
    }
}

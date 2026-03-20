<?php

namespace App\Support\Reports;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PrintFilterFormatter
{
    public static function format(array $filters): array
    {
        $formatted = [];

        foreach ($filters as $key => $value) {
            $entry = static::formatEntry((string) $key, $value);

            if ($entry !== null) {
                $formatted[] = $entry;
            }
        }

        return $formatted;
    }

    protected static function formatEntry(string $key, mixed $value): ?string
    {
        if (static::shouldSkip($value)) {
            return null;
        }

        $label = static::labelFor($key);
        $formattedValue = static::valueFor($key, $value);

        if ($formattedValue === null || $formattedValue === '') {
            return null;
        }

        return $label.': '.$formattedValue;
    }

    protected static function shouldSkip(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        if (is_array($value) && $value === []) {
            return true;
        }

        return false;
    }

    protected static function labelFor(string $key): string
    {
        return match ($key) {
            'search' => 'Search',
            'date_from' => 'Date From',
            'date_to' => 'Date To',
            'from_date' => 'Date From',
            'to_date' => 'Date To',
            'supplier_id' => 'Supplier',
            'item_id', 'inventory_item_id' => 'Item',
            'branch_id' => 'Branch',
            'customer_id' => 'Customer',
            'category_id' => 'Category',
            'purchase_order_id', 'po_id' => 'Purchase Order',
            'created_by', 'user_id', 'receiver_id' => 'User',
            'transaction_type' => 'Transaction Type',
            'reference_type' => 'Reference Type',
            default => Str::of($key)->replace('_', ' ')->title()->toString(),
        };
    }

    protected static function valueFor(string $key, mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('M j, Y g:i A');
        }

        if (is_array($value)) {
            $items = collect($value)
                ->map(fn (mixed $item) => static::valueFor($key, $item))
                ->filter(fn (?string $item) => $item !== null && $item !== '')
                ->values();

            return $items->isEmpty() ? null : $items->implode(', ');
        }

        return match ($key) {
            'supplier_id' => static::supplierName($value),
            'item_id', 'inventory_item_id' => static::itemName($value),
            'branch_id' => static::branchName($value),
            'customer_id' => static::customerName($value),
            'category_id' => static::categoryName($value),
            'purchase_order_id', 'po_id' => static::purchaseOrderName($value),
            'created_by', 'user_id', 'receiver_id' => static::userName($value),
            'date_from', 'date_to', 'from_date', 'to_date' => static::formatDate($value),
            'status' => static::humanizeValue($value, true),
            'transaction_type', 'reference_type', 'payment_method', 'type' => static::humanizeValue($value),
            default => static::scalarValue($value),
        };
    }

    protected static function supplierName(mixed $value): string
    {
        $id = (int) $value;
        $supplier = $id > 0 ? Supplier::query()->find($id) : null;

        return $supplier?->name ?: (string) $value;
    }

    protected static function itemName(mixed $value): string
    {
        $id = (int) $value;
        $item = $id > 0 ? InventoryItem::query()->find($id) : null;

        if (! $item) {
            return (string) $value;
        }

        return trim(implode(' ', array_filter([$item->item_code, $item->name])));
    }

    protected static function branchName(mixed $value): string
    {
        $id = (int) $value;
        $branch = $id > 0 ? Branch::query()->find($id) : null;

        return $branch?->name ?: (string) $value;
    }

    protected static function customerName(mixed $value): string
    {
        $id = (int) $value;
        $customer = $id > 0 ? Customer::query()->find($id) : null;

        if (! $customer) {
            return (string) $value;
        }

        return trim(implode(' ', array_filter([$customer->customer_code, $customer->name], fn ($part) => filled($part))));
    }

    protected static function categoryName(mixed $value): string
    {
        $id = (int) $value;
        $category = $id > 0 ? Category::query()->with('parent.parent.parent')->find($id) : null;

        return $category?->fullName() ?: (string) $value;
    }

    protected static function purchaseOrderName(mixed $value): string
    {
        $id = (int) $value;
        $purchaseOrder = $id > 0 ? PurchaseOrder::query()->find($id) : null;

        return $purchaseOrder?->po_number ?: (string) $value;
    }

    protected static function userName(mixed $value): string
    {
        $id = (int) $value;
        $user = $id > 0 ? User::query()->find($id) : null;

        return $user?->username ?: $user?->name ?: $user?->email ?: (string) $value;
    }

    protected static function formatDate(mixed $value): ?string
    {
        if (! is_scalar($value) || (string) $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('M j, Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    protected static function humanizeValue(mixed $value, bool $preserveAll = false): string
    {
        $string = static::scalarValue($value);

        if ($string === '') {
            return '';
        }

        if ($preserveAll && Str::lower($string) === 'all') {
            return 'All';
        }

        return Str::of($string)
            ->replace(['_', '-'], ' ')
            ->lower()
            ->title()
            ->toString();
    }

    protected static function scalarValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }
}

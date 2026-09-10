<?php

namespace App\Services\OrderSheet;

use App\Support\Reports\XlsxExport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrderSheetExcelExport
{
    public function download(string $date, array $menuItems, array $rows): BinaryFileResponse
    {
        $headers = [__('Date'), __('Order ID'), __('Customer'), __('Location')];
        foreach ($menuItems as $item) {
            $headers[] = $item['name'];
        }
        $headers = array_merge($headers, [__('Other dishes'), __('Other dishes quantity'), __('Total quantity'), __('Remarks')]);
        $export = [];
        $totals = array_fill(0, count($menuItems), 0);
        $extrasTotal = 0;
        $grandTotal = 0;
        foreach ($rows as $row) {
            if (blank($row['customer_name'])) {
                continue;
            }
            $quantities = [];
            foreach ($menuItems as $index => $item) {
                $quantity = (int) ($row['qty'][$item['id']] ?? 0);
                $quantities[] = $quantity;
                $totals[$index] += $quantity;
            }
            $extras = collect($row['extras'])->filter(fn ($extra) => (int) $extra['quantity'] > 0);
            $extraQuantity = (int) $extras->sum('quantity');
            $total = array_sum($quantities) + $extraQuantity;
            $extrasTotal += $extraQuantity;
            $grandTotal += $total;
            $export[] = array_merge([$date, $row['order_id'] ?? null, $row['customer_name'], $row['location']], $quantities, [
                $extras->map(fn ($extra) => $extra['menu_item_name'].' × '.(int) $extra['quantity'])->implode('; '),
                $extraQuantity, $total, $row['remarks'],
            ]);
        }
        $export[] = array_merge([$date, null, __('Total'), ''], $totals, ['', $extrasTotal, $grandTotal, '']);

        return XlsxExport::download($headers, $export, 'order-sheet-'.$date.'.xlsx', __('Order Sheet'));
    }
}

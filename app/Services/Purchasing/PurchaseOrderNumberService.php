<?php

namespace App\Services\Purchasing;

use App\Models\PurchaseOrder;
use Illuminate\Support\Str;

class PurchaseOrderNumberService
{
    public function generate(): string
    {
        $latest = PurchaseOrder::orderByDesc('id')->value('po_number');
        $next = 1;

        if ($latest && preg_match('/(\\d+)/', $latest, $m)) {
            $next = (int) $m[1] + 1;
        }

        do {
            $candidate = 'PO-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            $next++;
        } while (PurchaseOrder::where('po_number', $candidate)->exists());

        return $candidate;
    }
}

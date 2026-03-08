<?php

namespace App\Services\Spend;

use App\Models\ApInvoice;
use App\Models\ExpenseEvent;

class ExpenseEventService
{
    /**
     * @param array<string, mixed> $payload
     */
    public function log(ApInvoice $invoice, string $event, ?int $actorId = null, array $payload = []): ExpenseEvent
    {
        return ExpenseEvent::create([
            'invoice_id' => (int) $invoice->id,
            'event' => $event,
            'actor_id' => $actorId,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }
}

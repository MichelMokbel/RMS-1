<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Pos\SyncRequest;
use App\Services\POS\PosSyncService;

class SyncController extends Controller
{
    public function __invoke(SyncRequest $request, PosSyncService $sync)
    {
        $terminal = $request->attributes->get('pos_terminal');

        $payload = $request->validated();

        // Terminal cross-check (defense-in-depth).
        if ((string) $payload['terminal_code'] !== (string) $terminal->code || (int) $payload['branch_id'] !== (int) $terminal->branch_id) {
            return response()->json(['message' => 'AUTH_ERROR', 'reason' => 'TERMINAL_MISMATCH'], 403);
        }

        return response()->json($sync->sync(
            terminal: $terminal,
            user: $request->user(),
            deviceId: (string) $payload['device_id'],
            lastPulledAt: $payload['last_pulled_at'] ?? null,
            events: $payload['events'] ?? [],
        ));
    }
}

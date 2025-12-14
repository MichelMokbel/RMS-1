<?php

namespace App\Http\Controllers\Api\PettyCash;

use App\Http\Controllers\Controller;
use App\Services\PettyCash\PettyCashReconciliationService;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function store(Request $request, PettyCashReconciliationService $service)
    {
        $data = $request->validate([
            'wallet_id' => ['required', 'integer', 'exists:petty_cash_wallets,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
            'counted_balance' => ['required', 'numeric'],
            'note' => ['nullable', 'string'],
        ]);

        $recon = $service->reconcile($data['wallet_id'], $data, $request->user()->id);

        return response()->json($recon, 201);
    }
}

<?php

namespace App\Services\PettyCash;

use App\Models\PettyCashWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PettyCashWalletService
{
    public function create(array $data, int $userId): PettyCashWallet
    {
        return DB::transaction(function () use ($data, $userId) {
            return PettyCashWallet::create([
                'driver_id' => $data['driver_id'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'target_float' => $data['target_float'] ?? 0,
                'balance' => $data['balance'] ?? 0,
                'active' => $data['active'] ?? true,
                'created_by' => $userId,
            ]);
        });
    }

    public function activate(PettyCashWallet $wallet): PettyCashWallet
    {
        $wallet->active = true;
        $wallet->save();

        return $wallet;
    }

    public function deactivate(PettyCashWallet $wallet): PettyCashWallet
    {
        $hasOpenExpenses = $wallet->expenses()
            ->whereIn('status', ['submitted'])
            ->exists();

        if ($hasOpenExpenses) {
            throw ValidationException::withMessages(['wallet_id' => __('Cannot deactivate wallet with submitted expenses.')]);
        }

        $wallet->active = false;
        $wallet->save();

        return $wallet;
    }
}

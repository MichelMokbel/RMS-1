<?php

namespace App\Support\PettyCash;

class PettyCashWalletRules
{
    public function rules(string $prefix = 'walletForm.'): array
    {
        return [
            $prefix.'driver_id' => ['nullable', 'integer'],
            $prefix.'driver_name' => ['nullable', 'string', 'max:150'],
            $prefix.'target_float' => ['required', 'numeric', 'min:0'],
            $prefix.'balance' => ['required', 'numeric'],
            $prefix.'active' => ['boolean'],
        ];
    }
}


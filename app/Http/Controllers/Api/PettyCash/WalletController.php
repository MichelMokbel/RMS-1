<?php

namespace App\Http\Controllers\Api\PettyCash;

use App\Http\Controllers\Controller;
use App\Models\PettyCashWallet;
use App\Services\PettyCash\PettyCashWalletService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function index(Request $request)
    {
        $query = PettyCashWallet::query()
            ->when($request->boolean('active'), fn ($q) => $q->where('active', 1))
            ->when($request->boolean('inactive'), fn ($q) => $q->where('active', 0))
            ->when($request->filled('driver_id'), fn ($q) => $q->where('driver_id', $request->integer('driver_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->input('search');
                $q->where(function ($sub) use ($search) {
                    $sub->where('driver_name', 'like', '%'.$search.'%')
                        ->orWhere('driver_id', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('driver_name');

        return response()->json($query->get());
    }

    public function store(Request $request, PettyCashWalletService $service)
    {
        $data = $request->validate([
            'driver_id' => ['nullable', 'integer'],
            'driver_name' => ['nullable', 'string', 'max:150'],
            'target_float' => ['required', 'numeric', 'min:0'],
            'balance' => ['required', 'numeric'],
            'active' => ['boolean'],
        ]);

        $wallet = $service->create($data, $request->user()->id);

        return response()->json($wallet, 201);
    }

    public function update(int $id, Request $request)
    {
        $wallet = PettyCashWallet::findOrFail($id);

        $data = $request->validate([
            'driver_id' => ['nullable', 'integer'],
            'driver_name' => ['nullable', 'string', 'max:150'],
            'target_float' => ['sometimes', 'numeric', 'min:0'],
            'balance' => ['sometimes', 'numeric'],
            'active' => ['boolean'],
        ]);

        $wallet->update($data);

        return response()->json($wallet);
    }
}

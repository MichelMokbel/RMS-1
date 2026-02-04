<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Pos\LoginRequest;
use App\Models\PosTerminal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        /** @var User|null $user */
        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], (string) $user->password)) {
            return response()->json(['message' => 'AUTH_ERROR'], 401);
        }

        if (method_exists($user, 'isActive') && ! $user->isActive()) {
            return response()->json(['message' => 'AUTH_ERROR'], 403);
        }

        $terminal = PosTerminal::query()
            ->where('device_id', $data['device_id'])
            ->where('active', 1)
            ->first();

        if (! $terminal) {
            return response()->json([
                'message' => 'AUTH_ERROR',
                'error' => 'Device is not registered to a POS terminal.',
            ], 403);
        }

        $terminal->forceFill(['last_seen_at' => now()])->save();

        // Bind token to a specific device via abilities (and also keep name for debugging).
        $token = $user->createToken('pos:'.$data['device_id'], ['pos:*', 'device:'.$data['device_id']]);

        $roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->values() : collect();

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'role' => $roles->first(),
            'roles' => $roles,
            'branch_id' => (int) $terminal->branch_id,
            'terminal' => [
                'id' => (int) $terminal->id,
                'code' => (string) $terminal->code,
                'name' => (string) $terminal->name,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }
}

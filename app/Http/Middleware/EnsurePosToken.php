<?php

namespace App\Http\Middleware;

use App\Models\PosTerminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePosToken
{
    public function handle(Request $request, Closure $next, string $requiredAbility = 'pos:*'): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $user || ! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $token->can($requiredAbility) && ! $token->can('pos:*')) {
            return response()->json(['message' => 'AUTH_ERROR', 'reason' => 'ABILITY'], 403);
        }

        $reqDevice = $request->input('device_id') ?: $request->header('X-Device-Id');
        $deviceId = $this->resolveDeviceId($token, $reqDevice);
        if (! $deviceId) {
            return response()->json(['message' => 'AUTH_ERROR', 'reason' => 'DEVICE_MISMATCH'], 403);
        }

        $terminal = PosTerminal::query()
            ->where('device_id', $deviceId)
            ->where('active', 1)
            ->first();

        if (! $terminal) {
            return response()->json(['message' => 'AUTH_ERROR', 'reason' => 'TERMINAL_NOT_FOUND'], 403);
        }

        // Soft "last seen" tracking.
        $terminal->forceFill(['last_seen_at' => now()])->save();

        $request->attributes->set('pos_terminal', $terminal);
        $request->attributes->set('pos_device_id', $deviceId);

        return $next($request);
    }

    private function deviceIdFromTokenName(string $name): ?string
    {
        // Login creates tokens with name "pos:{device_id}".
        if (! str_starts_with($name, 'pos:')) {
            return null;
        }
        $deviceId = substr($name, strlen('pos:'));
        $deviceId = trim($deviceId);
        if ($deviceId === '' || strlen($deviceId) > 80) {
            return null;
        }

        return $deviceId;
    }

    private function resolveDeviceId($token, ?string $requestDeviceId): ?string
    {
        $fromAbility = $this->deviceIdFromAbilities(is_array($token->abilities ?? null) ? $token->abilities : []);
        $fromName = $this->deviceIdFromTokenName((string) ($token->name ?? ''));

        if ($requestDeviceId !== null && $requestDeviceId !== '') {
            $requestDeviceId = (string) $requestDeviceId;

            // Strongest check: token must explicitly allow this device.
            if ($token->can('device:'.$requestDeviceId)) {
                return $requestDeviceId;
            }

            // Backward-compat: accept legacy tokens that encode device in name.
            if ($fromName && $fromName === $requestDeviceId) {
                return $requestDeviceId;
            }

            return null;
        }

        // No device id provided in request (bootstrap/sequence): infer from token.
        return $fromAbility ?: $fromName;
    }

    private function deviceIdFromAbilities(array $abilities): ?string
    {
        foreach ($abilities as $ability) {
            if (! is_string($ability)) {
                continue;
            }
            if (! str_starts_with($ability, 'device:')) {
                continue;
            }
            $deviceId = trim(substr($ability, strlen('device:')));
            if ($deviceId !== '' && strlen($deviceId) <= 80) {
                return $deviceId;
            }
        }
        return null;
    }
}

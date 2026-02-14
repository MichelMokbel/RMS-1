<?php

namespace App\Http\Controllers\Api\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Pos\LoginRequest;
use App\Http\Requests\Api\Pos\RegisterTerminalRequest;
use App\Http\Requests\Api\Pos\SetupBranchesRequest;
use App\Models\PosTerminal;
use App\Models\User;
use App\Services\Security\BranchAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly BranchAccessService $branchAccess,
    ) {
    }

    public function branches(SetupBranchesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $auth = $this->resolvePosUser($data);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        /** @var User $user */
        $user = $auth;

        if (! $user->canUsePos()) {
            return response()->json(['message' => 'AUTH_ERROR'], 403);
        }

        $branches = DB::table('branches')
            ->where('is_active', 1)
            ->when(! $user->isAdmin(), function ($q) use ($user): void {
                $allowed = $this->branchAccess->allowedBranchIds($user);
                if ($allowed === []) {
                    $q->whereRaw('1 = 0');
                    return;
                }
                $q->whereIn('id', $allowed);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($branch) => [
                'id' => (int) $branch->id,
                'name' => (string) $branch->name,
                'code' => $branch->code !== null ? (string) $branch->code : null,
            ])
            ->values();

        return response()->json([
            'branches' => $branches,
        ]);
    }

    public function registerTerminal(RegisterTerminalRequest $request): JsonResponse
    {
        $data = $request->validated();
        $auth = $this->resolvePosUser($data);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        /** @var User $user */
        $user = $auth;

        if (! $user->canUsePos()) {
            return response()->json(['message' => 'AUTH_ERROR'], 403);
        }

        $branchIsActive = DB::table('branches')
            ->where('id', (int) $data['branch_id'])
            ->where('is_active', 1)
            ->exists();

        if (! $branchIsActive) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'branch_id' => ['Selected branch is not active.'],
                ],
            ], 422);
        }

        if (! $this->branchAccess->canAccessBranch($user, (int) $data['branch_id'])) {
            return response()->json([
                'message' => 'AUTH_ERROR',
                'error' => 'You are not allowed to register terminals for this branch.',
            ], 403);
        }

        $existingByDevice = PosTerminal::query()
            ->where('device_id', $data['device_id'])
            ->first();

        $codeConflict = PosTerminal::query()
            ->where('branch_id', (int) $data['branch_id'])
            ->where('code', (string) $data['code'])
            ->when($existingByDevice, fn ($q) => $q->whereKeyNot($existingByDevice->id))
            ->exists();

        if ($codeConflict) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'code' => ['Terminal code is already used in this branch.'],
                ],
            ], 422);
        }

        $terminal = PosTerminal::query()->updateOrCreate(
            ['device_id' => (string) $data['device_id']],
            [
                'branch_id' => (int) $data['branch_id'],
                'code' => (string) $data['code'],
                'name' => (string) $data['name'],
                'active' => true,
            ]
        );

        return response()->json([
            'terminal' => [
                'id' => (int) $terminal->id,
                'branch_id' => (int) $terminal->branch_id,
                'code' => (string) $terminal->code,
                'name' => (string) $terminal->name,
                'device_id' => (string) $terminal->device_id,
                'active' => (bool) $terminal->active,
            ],
            'created' => $terminal->wasRecentlyCreated,
        ]);
    }

    public function login(LoginRequest $request)
    {
        $data = $request->validated();
        $auth = $this->resolvePosUser($data);
        if ($auth instanceof JsonResponse) {
            return $auth;
        }
        /** @var User $user */
        $user = $auth;

        if (! $user->canUsePos()) {
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

        if (! $this->branchAccess->canAccessBranch($user, (int) $terminal->branch_id)) {
            return response()->json([
                'message' => 'AUTH_ERROR',
                'error' => 'You are not allowed to use terminals in this branch.',
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

    /**
     * @param  array<string, mixed>  $data
     * @return User|JsonResponse
     */
    private function resolvePosUser(array $data): User|JsonResponse
    {
        $identifier = strtolower(trim((string) ($data['username'] ?? $data['email'] ?? '')));

        /** @var User|null $user */
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$identifier])
            ->orWhereRaw('LOWER(username) = ?', [$identifier])
            ->first();

        if (! $user || ! Hash::check((string) $data['password'], (string) $user->password)) {
            return response()->json(['message' => 'AUTH_ERROR'], 401);
        }

        if (method_exists($user, 'isActive') && ! $user->isActive()) {
            return response()->json(['message' => 'AUTH_ERROR'], 403);
        }

        return $user;
    }
}

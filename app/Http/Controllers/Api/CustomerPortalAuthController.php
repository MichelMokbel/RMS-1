<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CustomerPortalConflictException;
use App\Http\Controllers\Controller;
use App\Services\Customers\CustomerPhoneVerificationService;
use App\Services\Customers\CustomerPortalRegistrationService;
use App\Services\Customers\PhoneNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class CustomerPortalAuthController extends Controller
{
    public function __construct(
        private readonly CustomerPortalRegistrationService $registration,
        private readonly CustomerPhoneVerificationService $verification,
        private readonly PhoneNumberService $phoneNumbers,
    ) {
    }

    public function registerStart(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['required', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->registration->start($data, $request->ip(), $request->userAgent());
        } catch (CustomerPortalConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'registration_token' => $result['registration_token'],
            'phone' => [
                'e164' => $result['customer']->phone_e164,
                'masked' => $this->phoneNumbers->mask((string) $result['customer']->phone_e164),
            ],
        ], 201);
    }

    public function registerVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration_token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:'.(int) config('customers.verification_code_length', 6)],
        ]);

        $challenge = $this->verification->resolveChallengeFromToken(
            $data['registration_token'],
            CustomerPhoneVerificationService::PURPOSE_SIGNUP
        );

        $challenge = $this->verification->verifyChallenge($challenge, $data['code']);
        $user = $challenge->user()->with('customer')->firstOrFail();
        $customer = $challenge->customer()->firstOrFail();

        $customer->forceFill([
            'phone_verified_at' => now(),
        ])->save();

        $token = $user->createToken('customer:'.$user->id, ['customer:*']);

        return response()->json([
            'token' => $token->plainTextToken,
            'account' => $this->serializeAccount($user->fresh('customer')),
        ]);
    }

    public function registerResend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registration_token' => ['required', 'string'],
        ]);

        $challenge = $this->verification->resolveChallengeFromToken(
            $data['registration_token'],
            CustomerPhoneVerificationService::PURPOSE_SIGNUP
        );

        $challenge = $this->verification->resendChallenge($challenge);

        return response()->json([
            'registration_token' => $this->verification->createChallengeToken($challenge, [
                'phone_raw' => $this->verification
                    ->decodeChallengeToken($data['registration_token'], CustomerPhoneVerificationService::PURPOSE_SIGNUP)['phone_raw'] ?? null,
            ]),
            'phone' => [
                'e164' => $challenge->phone_e164,
                'masked' => $this->phoneNumbers->mask($challenge->phone_e164),
            ],
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        /** @var User|null $user */
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($data['email']))])
            ->with('customer')
            ->first();

        if (! $user || ! $user->isActive() || ! Hash::check($data['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('The provided credentials are incorrect.'),
            ]);
        }

        if (! $user->isCustomerPortalUser()) {
            return response()->json(['message' => 'Customer portal access is not available for this account.'], 403);
        }

        $token = $user->createToken('customer:'.$user->id, ['customer:*']);

        return response()->json([
            'token' => $token->plainTextToken,
            'account' => $this->serializeAccount($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user()->load('customer');

        return response()->json([
            'account' => $this->serializeAccount($user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAccount(User $user): array
    {
        $customer = $user->customer;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'customer' => [
                'id' => $customer?->id,
                'name' => $customer?->name,
                'email' => $customer?->email,
                'phone' => $customer?->phone,
                'phone_e164' => $customer?->phone_e164,
                'phone_verified_at' => $customer?->phone_verified_at?->toIso8601String(),
                'delivery_address' => $customer?->delivery_address,
                'billing_address' => $customer?->billing_address,
                'customer_type' => $customer?->customer_type,
            ],
        ];
    }
}

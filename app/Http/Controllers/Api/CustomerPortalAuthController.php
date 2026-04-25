<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CustomerPortalConflictException;
use App\Http\Controllers\Controller;
use App\Notifications\CustomerPortalResetPassword;
use App\Services\Customers\CustomerPortalAccountService;
use App\Services\Customers\CustomerPhoneVerificationService;
use App\Services\Customers\CustomerPortalRegistrationService;
use App\Services\Customers\PhoneNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class CustomerPortalAuthController extends Controller
{
    public function __construct(
        private readonly CustomerPortalRegistrationService $registration,
        private readonly CustomerPhoneVerificationService $verification,
        private readonly PhoneNumberService $phoneNumbers,
        private readonly CustomerPortalAccountService $accounts,
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
            if ($this->verificationBypassEnabled()) {
                $result = $this->registration->startWithoutVerification($data);
                $token = $result['user']->createToken('customer:'.$result['user']->id, ['customer:*']);

                return response()->json([
                    'token' => $token->plainTextToken,
                    'account' => $this->accounts->serializeAccount($result['user']->fresh('customer')),
                    'verification_bypassed' => true,
                ], 201);
            }

            $result = $this->registration->start($data, $request->ip(), $request->userAgent());
        } catch (CustomerPortalConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'registration_token' => $result['registration_token'],
            'phone' => [
                'e164' => $result['user']->portal_phone_e164,
                'masked' => $this->phoneNumbers->mask((string) $result['user']->portal_phone_e164),
            ],
        ], 201);
    }

    public function registerVerify(Request $request): JsonResponse
    {
        if ($this->verificationBypassEnabled()) {
            return response()->json([
                'message' => 'Phone verification is temporarily bypassed. Complete registration in the first step.',
                'code' => 'PHONE_VERIFICATION_BYPASSED',
            ], 409);
        }

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
        $phoneRaw = $this->verification
            ->decodeChallengeToken($data['registration_token'], CustomerPhoneVerificationService::PURPOSE_SIGNUP)['phone_raw'] ?? $user->portal_phone;
        $this->accounts->markPortalPhoneVerified($user, (string) $phoneRaw, $challenge->phone_e164);

        $token = $user->createToken('customer:'.$user->id, ['customer:*']);

        return response()->json([
            'token' => $token->plainTextToken,
            'account' => $this->accounts->serializeAccount($user->fresh('customer')),
        ]);
    }

    public function registerResend(Request $request): JsonResponse
    {
        if ($this->verificationBypassEnabled()) {
            return response()->json([
                'message' => 'Phone verification is temporarily bypassed. Registration codes are not being sent.',
                'code' => 'PHONE_VERIFICATION_BYPASSED',
            ], 409);
        }

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
            'account' => $this->accounts->serializeAccount($user),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($data['email']))])
            ->first();

        if ($user && $user->isCustomerPortalUser()) {
            $token = Password::broker()->createToken($user);
            $user->notify(new CustomerPortalResetPassword($token));
        }

        return response()->json([
            'ok' => true,
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($data['email']))])
            ->first();

        if (! $user || ! $user->isCustomerPortalUser()) {
            throw ValidationException::withMessages([
                'email' => __('We could not reset the password for this account.'),
            ]);
        }

        $status = Password::broker()->reset(
            [
                'email' => $data['email'],
                'password' => $data['password'],
                'password_confirmation' => $data['password_confirmation'],
                'token' => $data['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Your password has been reset successfully.',
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
            'account' => $this->accounts->serializeAccount($user),
        ]);
    }

    private function verificationBypassEnabled(): bool
    {
        return (bool) config('customers.verification_bypass', false);
    }
}

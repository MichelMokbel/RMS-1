<?php

namespace App\Services\Customers;

use App\Contracts\PhoneVerificationProvider;
use App\Models\Customer;
use App\Models\CustomerPhoneVerificationChallenge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CustomerPhoneVerificationService
{
    public const PURPOSE_PHONE_CHANGE = 'phone_change';
    public const PURPOSE_SIGNUP = 'signup';

    public function __construct(
        private readonly PhoneVerificationProvider $provider,
    ) {
    }

    public function createChallenge(
        User $user,
        ?Customer $customer,
        string $purpose,
        string $phoneE164,
        ?string $requestIp = null,
        ?string $userAgent = null,
    ): CustomerPhoneVerificationChallenge {
        return DB::transaction(function () use ($user, $customer, $purpose, $phoneE164, $requestIp, $userAgent) {
            CustomerPhoneVerificationChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->whereNull('verified_at')
                ->whereNull('cancelled_at')
                ->update([
                    'cancelled_at' => now(),
                    'updated_at' => now(),
                ]);

            $challenge = CustomerPhoneVerificationChallenge::create([
                'user_id' => $user->id,
                'customer_id' => $customer?->id,
                'purpose' => $purpose,
                'phone_e164' => $phoneE164,
                'code_hash' => Hash::make($this->generateCode()),
                'expires_at' => now()->addMinutes((int) config('customers.verification_code_ttl_minutes', 10)),
                'attempt_count' => 0,
                'send_count' => 0,
                'request_ip' => $requestIp,
                'user_agent' => $userAgent,
            ]);

            return $this->dispatchCode($challenge);
        });
    }

    public function resendChallenge(CustomerPhoneVerificationChallenge $challenge): CustomerPhoneVerificationChallenge
    {
        if ($challenge->verified_at !== null || $challenge->cancelled_at !== null) {
            throw ValidationException::withMessages([
                'token' => __('This verification challenge is no longer active.'),
            ]);
        }

        $cooldownSeconds = (int) config('customers.verification_resend_cooldown_seconds', 60);
        if ($challenge->last_sent_at !== null && now()->diffInSeconds($challenge->last_sent_at) < $cooldownSeconds) {
            throw ValidationException::withMessages([
                'token' => __('Please wait before requesting another verification code.'),
            ]);
        }

        if ((int) $challenge->send_count >= (int) config('customers.verification_max_sends', 3)) {
            $challenge->forceFill([
                'cancelled_at' => now(),
            ])->save();

            throw ValidationException::withMessages([
                'token' => __('This verification challenge has expired. Start again to continue.'),
            ]);
        }

        return $this->dispatchCode($challenge);
    }

    public function verifyChallenge(CustomerPhoneVerificationChallenge $challenge, string $code): CustomerPhoneVerificationChallenge
    {
        if ($challenge->verified_at !== null || $challenge->cancelled_at !== null) {
            throw ValidationException::withMessages([
                'token' => __('This verification challenge is no longer active.'),
            ]);
        }

        if ($challenge->expires_at === null || $challenge->expires_at->isPast()) {
            $challenge->forceFill([
                'cancelled_at' => now(),
            ])->save();

            throw ValidationException::withMessages([
                'code' => __('The verification code has expired.'),
            ]);
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $attempts = (int) $challenge->attempt_count + 1;
            $updates = ['attempt_count' => $attempts];

            if ($attempts >= (int) config('customers.verification_max_attempts', 5)) {
                $updates['cancelled_at'] = now();
            }

            $challenge->forceFill($updates)->save();

            throw ValidationException::withMessages([
                'code' => __('The verification code is invalid.'),
            ]);
        }

        $challenge->forceFill([
            'verified_at' => now(),
        ])->save();

        return $challenge->fresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function createChallengeToken(CustomerPhoneVerificationChallenge $challenge, array $extra = []): string
    {
        $payload = [
            'challenge_id' => $challenge->id,
            'user_id' => $challenge->user_id,
            'customer_id' => $challenge->customer_id,
            'purpose' => $challenge->purpose,
            'token_expires_at' => CarbonImmutable::now()
                ->addMinutes((int) config('customers.registration_token_ttl_minutes', 30))
                ->toIso8601String(),
        ] + $extra;

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeChallengeToken(string $token, string $purpose): array
    {
        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages([
                'token' => __('The verification token is invalid.'),
            ]);
        }

        if (($payload['purpose'] ?? null) !== $purpose) {
            throw ValidationException::withMessages([
                'token' => __('The verification token is invalid.'),
            ]);
        }

        $expiresAt = $payload['token_expires_at'] ?? null;
        if (! $expiresAt || now()->parse((string) $expiresAt)->isPast()) {
            throw ValidationException::withMessages([
                'token' => __('The verification token has expired.'),
            ]);
        }

        return $payload;
    }

    public function resolveChallengeFromToken(string $token, string $purpose): CustomerPhoneVerificationChallenge
    {
        $payload = $this->decodeChallengeToken($token, $purpose);

        $challenge = CustomerPhoneVerificationChallenge::query()
            ->whereKey((int) $payload['challenge_id'])
            ->where('user_id', (int) $payload['user_id'])
            ->where('purpose', $purpose)
            ->when(
                array_key_exists('customer_id', $payload) && $payload['customer_id'] !== null,
                fn ($query) => $query->where('customer_id', (int) $payload['customer_id']),
                fn ($query) => $query->whereNull('customer_id')
            )
            ->first();

        if (! $challenge) {
            throw ValidationException::withMessages([
                'token' => __('The verification challenge could not be found.'),
            ]);
        }

        return $challenge;
    }

    private function dispatchCode(CustomerPhoneVerificationChallenge $challenge): CustomerPhoneVerificationChallenge
    {
        $code = $this->generateCode();
        $dispatch = $this->provider->sendVerificationCode(
            $challenge->phone_e164,
            $this->buildMessage($code),
            ['purpose' => $challenge->purpose, 'challenge_id' => $challenge->id]
        );

        $challenge->forceFill([
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('customers.verification_code_ttl_minutes', 10)),
            'send_count' => (int) $challenge->send_count + 1,
            'last_sent_at' => now(),
            'provider' => $dispatch['provider'] ?? null,
            'provider_message_id' => $dispatch['message_id'] ?? null,
            'cancelled_at' => null,
        ])->save();

        return $challenge->fresh();
    }

    private function buildMessage(string $code): string
    {
        $ttl = (int) config('customers.verification_code_ttl_minutes', 10);

        return "Layla Kitchen verification code: {$code}. Expires in {$ttl} minutes.";
    }

    private function generateCode(): string
    {
        $length = max(4, (int) config('customers.verification_code_length', 6));

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}

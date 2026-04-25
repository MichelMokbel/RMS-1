<?php

namespace App\Services\Customers;

use App\Exceptions\CustomerPortalConflictException;
use App\Models\CustomerPhoneVerificationChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerPortalRegistrationService
{
    public function __construct(
        private readonly PhoneNumberService $phoneNumbers,
        private readonly CustomerPhoneVerificationService $verification,
    ) {
    }

    /**
     * @param  array{name:string,email:string,password:string,phone:string,address?:string|null}  $data
     * @return array{registration_token:string,user:User,challenge:CustomerPhoneVerificationChallenge}
     */
    public function start(array $data, ?string $requestIp = null, ?string $userAgent = null): array
    {
        $email = Str::lower(trim($data['email']));
        $phoneRaw = trim($data['phone']);
        $phoneE164 = $this->phoneNumbers->normalizeOrFail($phoneRaw);

        return DB::transaction(function () use ($data, $email, $phoneRaw, $phoneE164, $requestIp, $userAgent) {
            $user = $this->createPortalUser(
                $data['name'],
                $email,
                $data['password'],
                $phoneRaw,
                $phoneE164,
                $data['address'] ?? null,
            );
            $challenge = $this->verification->createChallenge(
                $user,
                null,
                CustomerPhoneVerificationService::PURPOSE_SIGNUP,
                $phoneE164,
                $requestIp,
                $userAgent
            );

            $token = $this->verification->createChallengeToken($challenge, [
                'phone_raw' => $phoneRaw,
            ]);

            return [
                'registration_token' => $token,
                'user' => $user->fresh('customer'),
                'challenge' => $challenge,
            ];
        });
    }

    /**
     * @param  array{name:string,email:string,password:string,phone:string,address?:string|null}  $data
     * @return array{user:User}
     */
    public function startWithoutVerification(array $data): array
    {
        $email = Str::lower(trim($data['email']));
        $phoneRaw = trim($data['phone']);
        $phoneE164 = $this->phoneNumbers->normalizeOrFail($phoneRaw);

        return DB::transaction(function () use ($data, $email, $phoneRaw, $phoneE164) {
            $user = $this->createPortalUser(
                $data['name'],
                $email,
                $data['password'],
                $phoneRaw,
                $phoneE164,
                $data['address'] ?? null,
                true,
            );

            return [
                'user' => $user->fresh('customer'),
            ];
        });
    }

    private function createPortalUser(
        string $name,
        string $email,
        string $password,
        string $phoneRaw,
        string $phoneE164,
        ?string $address,
        bool $markVerified = false,
    ): User {
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($user) {
            $nonCustomerRoles = $user->getRoleNames()->filter(fn (string $role) => $role !== 'customer');
            if ($nonCustomerRoles->isNotEmpty()) {
                throw new CustomerPortalConflictException('This email is already used by a staff account.');
            }

            throw new CustomerPortalConflictException('An account already exists for this email.');
        }

        $user = User::create([
            'name' => $name,
            'username' => $this->generateUniqueUsername($email, $name),
            'email' => $email,
            'customer_id' => null,
            'portal_name' => $name,
            'portal_phone' => $phoneRaw,
            'portal_phone_e164' => $phoneE164,
            'portal_delivery_address' => $address,
            'portal_phone_verified_at' => $markVerified ? now() : null,
            'status' => 'active',
            'pos_enabled' => false,
            'password' => Hash::make($password),
        ]);

        if (! $user->hasRole('customer')) {
            $user->assignRole('customer');
        }

        return $user->fresh();
    }

    private function generateUniqueUsername(string $email, string $name): string
    {
        $base = Str::of(Str::before($email, '@'))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '.')
            ->trim('.')
            ->value();

        if ($base === '') {
            $base = Str::of($name)
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', '.')
                ->trim('.')
                ->value();
        }

        $base = Str::limit($base !== '' ? $base : 'customer', 40, '');
        $candidate = $base;
        $suffix = 1;

        while (User::query()->where('username', $candidate)->exists()) {
            $candidate = Str::limit($base, max(1, 40 - strlen((string) $suffix) - 1), '').'.'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}

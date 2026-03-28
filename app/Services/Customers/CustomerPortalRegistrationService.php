<?php

namespace App\Services\Customers;

use App\Exceptions\CustomerPortalConflictException;
use App\Models\Customer;
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
     * @return array{registration_token:string,user:User,customer:Customer,challenge:CustomerPhoneVerificationChallenge}
     */
    public function start(array $data, ?string $requestIp = null, ?string $userAgent = null): array
    {
        $email = Str::lower(trim($data['email']));
        $phoneRaw = trim($data['phone']);
        $phoneE164 = $this->phoneNumbers->normalizeOrFail($phoneRaw);

        return DB::transaction(function () use ($data, $email, $phoneRaw, $phoneE164, $requestIp, $userAgent) {
            $customer = $this->resolveCustomer($data['name'], $email, $phoneRaw, $phoneE164, $data['address'] ?? null);
            $user = $this->resolveUser($customer, $data['name'], $email, $data['password']);
            $challenge = $this->verification->createChallenge(
                $user,
                $customer,
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
                'customer' => $customer->fresh('user'),
                'challenge' => $challenge,
            ];
        });
    }

    private function resolveCustomer(
        string $name,
        string $email,
        string $phoneRaw,
        string $phoneE164,
        ?string $address,
    ): Customer {
        $matchedByEmail = Customer::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->get();

        if ($matchedByEmail->count() > 1) {
            throw new CustomerPortalConflictException('Multiple customers already use this email. Please contact support.');
        }

        if ($matchedByEmail->count() === 1) {
            return $this->hydrateMatchedCustomer($matchedByEmail->first(), $name, $email, $phoneRaw, $phoneE164, $address);
        }

        $matchedByPhone = Customer::query()
            ->where('phone_e164', $phoneE164)
            ->get();

        if ($matchedByPhone->count() > 1) {
            throw new CustomerPortalConflictException('Multiple customers already use this phone number. Please contact support.');
        }

        if ($matchedByPhone->count() === 1) {
            return $this->hydrateMatchedCustomer($matchedByPhone->first(), $name, $email, $phoneRaw, $phoneE164, $address);
        }

        return Customer::create([
            'name' => $name,
            'contact_name' => $name,
            'customer_type' => Customer::TYPE_RETAIL,
            'phone' => $phoneRaw,
            'phone_e164' => $phoneE164,
            'email' => $email,
            'delivery_address' => $address,
            'country' => 'Qatar',
            'credit_limit' => 0,
            'credit_terms_days' => 0,
            'is_active' => true,
        ]);
    }

    private function hydrateMatchedCustomer(
        Customer $customer,
        string $name,
        string $email,
        string $phoneRaw,
        string $phoneE164,
        ?string $address,
    ): Customer {
        if ($customer->user && ! $customer->user->hasRole('customer')) {
            throw new CustomerPortalConflictException('This customer is already linked to a backoffice user.');
        }

        $customer->fill([
            'name' => $name,
            'contact_name' => $customer->contact_name ?: $name,
            'phone' => $phoneRaw,
            'phone_e164' => $phoneE164,
            'email' => $email,
            'delivery_address' => $address ?: $customer->delivery_address,
            'is_active' => true,
        ])->save();

        return $customer->fresh();
    }

    private function resolveUser(Customer $customer, string $name, string $email, string $password): User
    {
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($customer->user && $user && $customer->user->isNot($user)) {
            throw new CustomerPortalConflictException('This customer is already linked to another account.');
        }

        if ($user) {
            $nonCustomerRoles = $user->getRoleNames()->filter(fn (string $role) => $role !== 'customer');
            if ($nonCustomerRoles->isNotEmpty()) {
                throw new CustomerPortalConflictException('This email is already used by a staff account.');
            }

            if ($user->customer_id && (int) $user->customer_id !== (int) $customer->id) {
                throw new CustomerPortalConflictException('This email is already linked to another customer.');
            }

            if ($customer->phone_verified_at !== null && (int) $user->customer_id === (int) $customer->id) {
                throw new CustomerPortalConflictException('An account already exists for this customer.');
            }

            $user->fill([
                'name' => $name,
                'email' => $email,
                'customer_id' => $customer->id,
                'status' => 'active',
                'pos_enabled' => false,
                'password' => Hash::make($password),
            ]);

            if (! $user->username) {
                $user->username = $this->generateUniqueUsername($email, $name);
            }

            $user->save();
        } else {
            $user = User::create([
                'name' => $name,
                'username' => $this->generateUniqueUsername($email, $name),
                'email' => $email,
                'customer_id' => $customer->id,
                'status' => 'active',
                'pos_enabled' => false,
                'password' => Hash::make($password),
            ]);
        }

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

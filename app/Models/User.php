<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'customer_id',
        'portal_name',
        'portal_phone',
        'portal_phone_e164',
        'portal_delivery_address',
        'portal_phone_verified_at',
        'password',
        'status',
        'pos_enabled',
    ];

    /**
     * Guard name used by Spatie permissions.
     *
     * @var string
     */
    protected $guard_name = 'web';

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'customer_id' => 'integer',
            'portal_phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'pos_enabled' => 'boolean',
        ];
    }

    /**
     * Determine if the user is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function canUsePos(): bool
    {
        return $this->isActive()
            && (bool) ($this->pos_enabled ?? false)
            && ($this->hasRole('admin') || $this->can('pos.login'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function isCustomerPortalUser(): bool
    {
        return $this->hasRole('customer');
    }

    /**
     * @return array<int, int>
     */
    public function allowedBranchIds(): array
    {
        if ($this->isAdmin()) {
            if (! Schema::hasTable('branches')) {
                return [];
            }

            $q = \Illuminate\Support\Facades\DB::table('branches')->select('id');
            if (Schema::hasColumn('branches', 'is_active')) {
                $q->where('is_active', 1);
            }

            return $q->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }

        if (! Schema::hasTable('user_branch_access')) {
            return [];
        }

        return $this->branches()
            ->pluck('branches.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function branches()
    {
        return $this->belongsToMany(
            Branch::class,
            'user_branch_access',
            'user_id',
            'branch_id'
        )->withTimestamps();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $source = $this->username ?? $this->email ?? '';

        return Str::of($source)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('') ?: 'U';
    }
}

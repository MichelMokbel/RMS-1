<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Customer extends Model
{
    use HasFactory;

    public const TYPE_RETAIL = 'retail';
    public const TYPE_CORPORATE = 'corporate';
    public const TYPE_SUBSCRIPTION = 'subscription';

    protected $table = 'customers';

    protected $fillable = [
        'customer_code',
        'name',
        'customer_type',
        'contact_name',
        'phone',
        'email',
        'billing_address',
        'delivery_address',
        'country',
        'default_payment_method_id',
        'credit_limit',
        'credit_terms_days',
        'credit_status',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'credit_limit' => 'decimal:3',
        'credit_terms_days' => 'integer',
        'default_payment_method_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', 1);
    }

    public function scopeType(Builder $query, ?string $type): Builder
    {
        return $type ? $query->where('customer_type', $type) : $query;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($term) {
            $inner->where('name', 'like', '%'.$term.'%')
                ->orWhere('phone', 'like', '%'.$term.'%')
                ->orWhere('email', 'like', '%'.$term.'%')
                ->orWhere('customer_code', 'like', '%'.$term.'%');
        });
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    public function isCreditCustomer(): bool
    {
        return in_array($this->customer_type, [self::TYPE_CORPORATE, self::TYPE_SUBSCRIPTION], true);
    }

    public function createdBy()
    {
        return class_exists(User::class)
            ? $this->belongsTo(User::class, 'created_by')
            : null;
    }

    public function updatedBy()
    {
        return class_exists(User::class)
            ? $this->belongsTo(User::class, 'updated_by')
            : null;
    }
}

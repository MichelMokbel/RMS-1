<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MembershipPromotion extends Model
{
    public const DISCOUNT_FIXED = 'fixed';

    public const DISCOUNT_PERCENTAGE = 'percentage';

    public const ELIGIBILITY_FIRST = 'first';

    public const ELIGIBILITY_RENEWAL = 'renewal';

    public const ELIGIBILITY_BOTH = 'both';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'company_id',
        'code',
        'discount_type',
        'fixed_amount_cents',
        'percentage_basis_points',
        'purchase_eligibility',
        'starts_at',
        'ends_at',
        'total_limit',
        'per_customer_limit',
        'status',
        'revision',
        'first_activated_at',
        'expired_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'fixed_amount_cents' => 'integer',
        'percentage_basis_points' => 'integer',
        'total_limit' => 'integer',
        'per_customer_limit' => 'integer',
        'revision' => 'integer',
        'first_activated_at' => UtcDateTime::class,
        'expired_at' => UtcDateTime::class,
        'starts_at' => UtcDateTime::class,
        'ends_at' => UtcDateTime::class,
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(
            MembershipPlan::class,
            'membership_promotion_plans',
            'promotion_id',
            'membership_plan_id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function displayStatus(?CarbonInterface $now = null): string
    {
        $now ??= now('UTC');

        if ($this->status === self::STATUS_DRAFT) {
            return 'Draft';
        }
        if ($this->status === self::STATUS_EXPIRED || $now->greaterThanOrEqualTo($this->ends_at)) {
            return 'Expired';
        }
        if ($this->status === self::STATUS_PAUSED) {
            return 'Paused';
        }
        if ($this->status === self::STATUS_ACTIVE && $now->lessThan($this->starts_at)) {
            return 'Scheduled';
        }
        if ($this->status === self::STATUS_ACTIVE) {
            return 'Live';
        }

        return ucfirst((string) $this->status);
    }
}

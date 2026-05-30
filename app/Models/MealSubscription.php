<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class MealSubscription extends Model
{
    use HasFactory;

    protected $table = 'meal_subscriptions';

    protected $fillable = [
        'subscription_code',
        'customer_id',
        'branch_id',
        'status',
        'start_date',
        'end_date',
        'plan_meals_total',
        'meals_used',
        'meal_plan_request_id',
        'default_order_type',
        'delivery_time',
        'address_snapshot',
        'phone_snapshot',
        'preferred_role',
        'include_salad',
        'include_dessert',
        'notes',
        'created_by',
        'source_payment_id',
        'uses_invoice_tracking',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'include_salad' => 'boolean',
        'include_dessert' => 'boolean',
        'plan_meals_total' => 'integer',
        'meals_used' => 'integer',
        'meal_plan_request_id' => 'integer',
        'source_payment_id' => 'integer',
        'renewal_subscription_id' => 'integer',
        'uses_invoice_tracking' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function sourcePayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'source_payment_id');
    }

    public function renewalSuccessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewal_subscription_id');
    }

    public function subscriptionOrders(): HasMany
    {
        return $this->hasMany(MealSubscriptionOrder::class, 'subscription_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(MealSubscriptionDay::class, 'subscription_id');
    }

    public function pauses(): HasMany
    {
        return $this->hasMany(MealSubscriptionPause::class, 'subscription_id');
    }

    public function scopeWithRenewalState(Builder $query): Builder
    {
        return $query->addSelect([
            'renewal_subscription_id' => $this->renewalCandidateSubquery(),
        ]);
    }

    public function scopeExpiredNotRenewed(Builder $query): Builder
    {
        return $query
            ->where('status', 'expired')
            ->whereNotExists($this->renewalCandidateExistsSubquery());
    }

    public function getIsRenewedAttribute(): bool
    {
        if ($this->status !== 'expired') {
            return false;
        }

        if (array_key_exists('renewal_subscription_id', $this->attributes)) {
            return $this->attributes['renewal_subscription_id'] !== null;
        }

        return $this->singleRecordRenewalCandidateQuery()->exists();
    }

    public function getIsExpiredNotRenewedAttribute(): bool
    {
        return $this->status === 'expired' && ! $this->is_renewed;
    }

    public function resolveRenewalSuccessor(): ?self
    {
        $candidateId = $this->renewal_subscription_id;

        if ($candidateId === null) {
            $candidateId = $this->singleRecordRenewalCandidateQuery()->value('id');
        }

        if (! $candidateId) {
            return null;
        }

        if ($this->relationLoaded('renewalSuccessor') && (int) optional($this->renewalSuccessor)->getKey() === (int) $candidateId) {
            return $this->renewalSuccessor;
        }

        return self::query()->with('customer:id,name')->find($candidateId);
    }

    public function isActiveOn($date): bool
    {
        $date = \Illuminate\Support\Carbon::parse($date);
        if ($this->status !== 'active') {
            return false;
        }
        if ($date->lt($this->start_date)) {
            return false;
        }
        if ($this->end_date && $date->gt($this->end_date)) {
            return false;
        }
        // Pause ranges
        foreach ($this->pauses as $pause) {
            if ($date->greaterThanOrEqualTo($pause->pause_start) && $date->lessThanOrEqualTo($pause->pause_end)) {
                return false;
            }
        }
        // Weekday enabled
        $weekday = (int) $date->format('N'); // 1-7
        return $this->weekdayEnabled($weekday);
    }

    public function weekdayEnabled(int $weekday): bool
    {
        return $this->days->contains('weekday', $weekday);
    }

    private function renewalCandidateSubquery()
    {
        $table = $this->getTable();

        return DB::table($table.' as renewal_candidates')
            ->select('renewal_candidates.id')
            ->whereColumn('renewal_candidates.customer_id', $table.'.customer_id')
            ->whereColumn('renewal_candidates.id', '!=', $table.'.id')
            ->where(function ($query) use ($table) {
                $query
                    ->whereColumn('renewal_candidates.created_at', '>', $table.'.created_at')
                    ->orWhere(function ($sameTimestamp) use ($table) {
                        $sameTimestamp
                            ->whereColumn('renewal_candidates.created_at', '=', $table.'.created_at')
                            ->whereColumn('renewal_candidates.id', '>', $table.'.id');
                    });
            })
            ->orderBy('renewal_candidates.created_at')
            ->orderBy('renewal_candidates.id')
            ->limit(1);
    }

    private function renewalCandidateExistsSubquery()
    {
        $table = $this->getTable();

        return DB::table($table.' as renewal_candidates')
            ->selectRaw('1')
            ->whereColumn('renewal_candidates.customer_id', $table.'.customer_id')
            ->whereColumn('renewal_candidates.id', '!=', $table.'.id')
            ->where(function ($query) use ($table) {
                $query
                    ->whereColumn('renewal_candidates.created_at', '>', $table.'.created_at')
                    ->orWhere(function ($sameTimestamp) use ($table) {
                        $sameTimestamp
                            ->whereColumn('renewal_candidates.created_at', '=', $table.'.created_at')
                            ->whereColumn('renewal_candidates.id', '>', $table.'.id');
                    });
            });
    }

    private function singleRecordRenewalCandidateQuery(): Builder
    {
        return self::query()
            ->where('customer_id', $this->customer_id)
            ->whereKeyNot($this->id)
            ->where(function ($query) {
                $query
                    ->where('created_at', '>', $this->created_at)
                    ->orWhere(function ($sameTimestamp) {
                        $sameTimestamp
                            ->where('created_at', '=', $this->created_at)
                            ->where('id', '>', $this->id);
                    });
            })
            ->orderBy('created_at')
            ->orderBy('id');
    }
}

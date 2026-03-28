<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPhoneVerificationChallenge extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer_id',
        'purpose',
        'phone_e164',
        'code_hash',
        'expires_at',
        'attempt_count',
        'send_count',
        'last_sent_at',
        'verified_at',
        'cancelled_at',
        'provider',
        'provider_message_id',
        'request_ip',
        'user_agent',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'customer_id' => 'integer',
        'attempt_count' => 'integer',
        'send_count' => 'integer',
        'expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'verified_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function isActive(): bool
    {
        return $this->verified_at === null
            && $this->cancelled_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}

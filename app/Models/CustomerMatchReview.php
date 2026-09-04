<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMatchReview extends Model
{
    use HasFactory;

    public const STATUS_DIFFERENT = 'different';

    public const STATUS_MERGED = 'merged';

    public const STATUS_PENDING = 'pending';

    protected $fillable = [
        'user_id',
        'customer_id',
        'candidate_customer_id',
        'reason_codes',
        'profile_fingerprint',
        'status',
        'ai_suggestion',
        'ai_checked_at',
        'decision_note',
        'reviewed_by',
        'reviewed_at',
        'merge_audit_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'customer_id' => 'integer',
        'candidate_customer_id' => 'integer',
        'reason_codes' => 'array',
        'ai_suggestion' => 'array',
        'ai_checked_at' => 'datetime',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'merge_audit_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function candidateCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'candidate_customer_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function mergeAudit(): BelongsTo
    {
        return $this->belongsTo(AccountingAuditLog::class, 'merge_audit_id');
    }
}

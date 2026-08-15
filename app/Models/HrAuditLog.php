<?php

namespace App\Models;

use App\Models\Concerns\HrAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class HrAuditLog extends Model
{
    use HrAppendOnly;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'actor_id', 'action', 'subject_type', 'subject_id',
        'request_id', 'ip_address', 'user_agent', 'payload', 'created_at',
    ];

    protected $casts = [
        'company_id' => 'integer', 'actor_id' => 'integer', 'subject_id' => 'integer',
        'payload' => 'array', 'created_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}

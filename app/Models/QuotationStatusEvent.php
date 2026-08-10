<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationStatusEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'quotation_id', 'quotation_version_id', 'event', 'from_status',
        'to_status', 'note', 'metadata', 'actor_id', 'created_at',
    ];

    protected $casts = [
        'quotation_id' => 'integer', 'quotation_version_id' => 'integer',
        'metadata' => 'array', 'actor_id' => 'integer', 'created_at' => 'datetime',
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(QuotationVersion::class, 'quotation_version_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

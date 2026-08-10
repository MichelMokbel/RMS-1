<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuotationVersion extends Model
{
    protected $fillable = [
        'quotation_id', 'revision', 'quotation_number', 'status', 'snapshot',
        'subtotal_cents', 'discount_total_cents', 'total_cents', 'created_by', 'finalized_at',
    ];

    protected $casts = [
        'quotation_id' => 'integer', 'revision' => 'integer', 'snapshot' => 'array',
        'subtotal_cents' => 'integer', 'discount_total_cents' => 'integer',
        'total_cents' => 'integer', 'created_by' => 'integer', 'finalized_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Quotation versions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Quotation versions are immutable.'));
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(QuotationArtifact::class);
    }
}

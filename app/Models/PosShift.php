<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosShift extends Model
{
    use HasFactory;

    protected $table = 'pos_shifts';

    protected $fillable = [
        'branch_id',
        'terminal_id',
        'device_id',
        'user_id',
        'active',
        'status',
        'opening_cash_cents',
        'opening_cash_adjusted_at',
        'opening_cash_adjusted_by',
        'opening_cash_adjustment_reason',
        'closing_cash_cents',
        'closing_card_cents',
        'expected_cash_cents',
        'variance_cents',
        'opened_at',
        'closed_at',
        'notes',
        'created_by',
        'closed_by',
    ];

    protected $casts = [
        'active' => 'boolean',
        'terminal_id' => 'integer',
        'opening_cash_cents' => 'integer',
        'opening_cash_adjusted_at' => 'datetime',
        'opening_cash_adjusted_by' => 'integer',
        'closing_cash_cents' => 'integer',
        'closing_card_cents' => 'integer',
        'expected_cash_cents' => 'integer',
        'variance_cents' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'pos_shift_id');
    }

    public function isOpen(): bool
    {
        return (bool) $this->active && $this->status === 'open' && $this->closed_at === null;
    }
}

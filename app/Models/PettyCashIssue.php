<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashIssue extends Model
{
    use HasFactory;

    protected $table = 'petty_cash_issues';
    public $timestamps = false;
    public const CREATED_AT = 'created_at';

    protected $fillable = [
        'wallet_id',
        'issue_date',
        'amount',
        'method',
        'reference',
        'issued_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(PettyCashWallet::class, 'wallet_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}

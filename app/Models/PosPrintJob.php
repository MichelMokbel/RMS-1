<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosPrintJob extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $table = 'pos_print_jobs';

    protected $fillable = [
        'client_job_id',
        'branch_id',
        'target_terminal_id',
        'job_type',
        'payload',
        'metadata',
        'status',
        'attempt_count',
        'max_attempts',
        'next_retry_at',
        'claimed_at',
        'claim_expires_at',
        'acked_at',
        'last_error_code',
        'last_error_message',
        'created_by',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'target_terminal_id' => 'integer',
        'payload' => 'array',
        'metadata' => 'array',
        'attempt_count' => 'integer',
        'max_attempts' => 'integer',
        'next_retry_at' => 'datetime',
        'claimed_at' => 'datetime',
        'claim_expires_at' => 'datetime',
        'acked_at' => 'datetime',
        'created_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function terminal()
    {
        return $this->belongsTo(PosTerminal::class, 'target_terminal_id');
    }
}

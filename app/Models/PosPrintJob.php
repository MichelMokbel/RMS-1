<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosPrintJob extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_PRINTED = 'printed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PENDING = self::STATUS_QUEUED; // backward alias
    public const STATUS_COMPLETED = self::STATUS_PRINTED; // backward alias

    protected $table = 'pos_print_jobs';

    protected $fillable = [
        'client_job_id',
        'source_terminal_id',
        'branch_id',
        'target_terminal_id',
        'target',
        'doc_type',
        'payload_base64',
        'client_created_at',
        'job_type',
        'payload',
        'metadata',
        'status',
        'attempt_count',
        'max_attempts',
        'next_retry_at',
        'claimed_at',
        'claimed_by_terminal_id',
        'claim_token',
        'claim_expires_at',
        'acked_at',
        'processing_ms',
        'last_error_code',
        'last_error_message',
        'created_by',
    ];

    protected $casts = [
        'source_terminal_id' => 'integer',
        'branch_id' => 'integer',
        'target_terminal_id' => 'integer',
        'payload' => 'array',
        'metadata' => 'array',
        'attempt_count' => 'integer',
        'max_attempts' => 'integer',
        'client_created_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'claimed_at' => 'datetime',
        'claimed_by_terminal_id' => 'integer',
        'claim_expires_at' => 'datetime',
        'acked_at' => 'datetime',
        'processing_ms' => 'integer',
        'created_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sourceTerminal()
    {
        return $this->belongsTo(PosTerminal::class, 'source_terminal_id');
    }

    public function terminal()
    {
        return $this->belongsTo(PosTerminal::class, 'target_terminal_id');
    }
}

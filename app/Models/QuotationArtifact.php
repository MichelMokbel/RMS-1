<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationArtifact extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'quotation_version_id', 'format', 'disk', 'storage_key', 'mime_type',
        'size_bytes', 'checksum_sha256', 'generation_status', 'generated_at', 'error_message',
    ];

    protected $casts = [
        'quotation_version_id' => 'integer', 'size_bytes' => 'integer', 'generated_at' => 'datetime',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(QuotationVersion::class, 'quotation_version_id');
    }

    public function isReady(): bool
    {
        return $this->generation_status === self::STATUS_READY;
    }
}

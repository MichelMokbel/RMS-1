<?php

namespace App\Models;

use App\Models\Concerns\HrAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrDocumentVersion extends Model
{
    use HrAppendOnly;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'document_id', 'version_number', 'storage_disk', 'object_key',
        'original_name', 'mime_type', 'size_bytes', 'sha256', 'scan_status',
        'scan_metadata', 'uploaded_by', 'created_at',
    ];

    protected $casts = [
        'company_id' => 'integer', 'document_id' => 'integer', 'version_number' => 'integer',
        'size_bytes' => 'integer', 'scan_metadata' => 'array', 'created_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(HrDocument::class, 'document_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

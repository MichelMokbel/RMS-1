<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAsset extends Model
{
    protected $fillable = [
        'company_id', 'kind', 'disk', 'storage_key', 'original_name', 'mime_type',
        'size_bytes', 'checksum_sha256', 'uploaded_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'size_bytes' => 'integer',
        'uploaded_by' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

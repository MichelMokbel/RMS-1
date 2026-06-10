<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ApInvoiceAttachment extends Model
{
    use HasFactory;

    private const DISK = 's3';

    protected $table = 'ap_invoice_attachments';
    public $timestamps = false;
    public const CREATED_AT = 'created_at';

    protected $fillable = [
        'invoice_id',
        'file_path',
        'original_name',
        'uploaded_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected $appends = [
        'url',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ApInvoice::class, 'invoice_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        return Storage::disk(self::DISK)->url($this->file_path);
    }
}

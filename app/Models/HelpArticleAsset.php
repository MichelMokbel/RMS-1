<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class HelpArticleAsset extends Model
{
    protected $fillable = [
        'article_id',
        'key',
        'disk',
        'path',
        'alt_text',
        'viewport',
        'checksum',
        'captured_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(HelpArticle::class, 'article_id');
    }

    public function publicUrl(): ?string
    {
        if (! $this->path) {
            return null;
        }

        try {
            return Storage::disk($this->disk ?: 'public')->url($this->path);
        } catch (\Throwable) {
            return null;
        }
    }
};

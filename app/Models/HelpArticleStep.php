<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelpArticleStep extends Model
{
    protected $fillable = [
        'article_id',
        'sort_order',
        'title',
        'body_markdown',
        'image_key',
        'cta_label',
        'cta_route',
        'cta_route_params',
    ];

    protected function casts(): array
    {
        return [
            'cta_route_params' => 'array',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(HelpArticle::class, 'article_id');
    }

    public function imageAsset(): BelongsTo
    {
        return $this->belongsTo(HelpArticleAsset::class, 'image_key', 'key');
    }

    public function ctaUrl(): ?string
    {
        if (! $this->cta_route || ! \Route::has($this->cta_route)) {
            return null;
        }

        try {
            return route($this->cta_route, $this->cta_route_params ?? []);
        } catch (\Throwable) {
            return null;
        }
    }
};

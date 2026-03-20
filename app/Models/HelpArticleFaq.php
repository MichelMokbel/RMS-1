<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelpArticleFaq extends Model
{
    protected $fillable = [
        'article_id',
        'module',
        'sort_order',
        'question',
        'answer_markdown',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(HelpArticle::class, 'article_id');
    }
};

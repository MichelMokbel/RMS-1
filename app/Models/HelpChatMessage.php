<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelpChatMessage extends Model
{
    protected $fillable = [
        'session_id',
        'role',
        'content',
        'citations',
        'confidence',
        'fallback',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'citations' => 'array',
            'fallback' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(HelpChatSession::class, 'session_id');
    }
};

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HelpChatSession extends Model
{
    protected $fillable = [
        'user_id',
        'locale',
        'title',
        'last_question',
        'last_answered_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'last_answered_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(HelpChatMessage::class, 'session_id')->orderBy('id');
    }
};

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTemplateVersion extends Model
{
    protected $fillable = [
        'document_template_id', 'version', 'schema_version', 'page_settings',
        'styles', 'table_columns', 'blocks', 'created_by',
    ];

    protected $casts = [
        'document_template_id' => 'integer',
        'version' => 'integer',
        'schema_version' => 'integer',
        'page_settings' => 'array',
        'styles' => 'array',
        'table_columns' => 'array',
        'blocks' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Template versions are immutable. Create a new version.'));
        static::deleting(fn () => throw new \LogicException('Template versions are immutable. Archive the template instead.'));
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

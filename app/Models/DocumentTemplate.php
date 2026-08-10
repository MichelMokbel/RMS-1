<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentTemplate extends Model
{
    public const TYPE_QUOTATION = 'quotation';

    protected $fillable = [
        'company_id', 'type', 'name', 'is_active', 'is_default',
        'current_version_id', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'current_version_id' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentTemplateVersion::class, 'document_template_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplateVersion::class, 'current_version_id');
    }

    public function scopeQuotations(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_QUOTATION);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

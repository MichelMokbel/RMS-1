<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrDocumentType extends Model
{
    protected $fillable = [
        'company_id', 'code', 'name', 'description', 'is_required', 'required_for',
        'requires_issue_date', 'requires_expiry_date', 'expiry_warning_days', 'is_sensitive', 'is_active',
    ];

    protected $casts = [
        'company_id' => 'integer', 'is_required' => 'boolean', 'required_for' => 'array',
        'requires_issue_date' => 'boolean', 'requires_expiry_date' => 'boolean',
        'expiry_warning_days' => 'array', 'is_sensitive' => 'boolean', 'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(HrDocument::class, 'document_type_id');
    }
}

<?php

namespace App\Models;

use App\Enums\HR\DocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrDocument extends Model
{
    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'company_id', 'employee_id', 'document_type_id', 'document_number', 'issue_date',
        'expiry_date', 'issuing_authority', 'status', 'current_version_id', 'verified_at',
        'verified_by', 'notes', 'archived_at', 'archived_by',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer', 'employee_id' => 'integer', 'document_type_id' => 'integer',
            'document_number' => 'encrypted', 'issue_date' => 'date', 'expiry_date' => 'date',
            'status' => DocumentStatus::class, 'current_version_id' => 'integer',
            'verified_at' => 'datetime', 'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $document): void {
            if ($document->versions()->exists()) {
                throw new \LogicException('Documents with versions cannot be deleted; archive the document instead.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(HrDocumentType::class, 'document_type_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(HrDocumentVersion::class, 'document_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(HrDocumentVersion::class, 'current_version_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }
}

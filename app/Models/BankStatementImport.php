<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_account_id',
        'company_id',
        'file_name',
        'storage_path',
        'imported_rows',
        'status',
        'processed_at',
        'uploaded_by',
    ];

    protected $casts = [
        'imported_rows' => 'integer',
        'processed_at' => 'datetime',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'statement_import_id');
    }
}

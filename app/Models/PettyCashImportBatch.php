<?php

namespace App\Models;

use App\Enums\PettyCash\PettyCashImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashImportBatch extends Model
{
    protected $fillable = [
        'company_id',
        'import_mode',
        'business_date',
        'date_from',
        'date_to',
        'default_category_id',
        'default_supplier_id',
        'default_wallet_id',
        'default_paid',
        'funding_source',
        'default_bank_account_id',
        'status',
        'revision',
        'parser_version',
        'source_name',
        'storage_disk',
        'object_key',
        'sha256',
        'idempotency_key',
        'stats',
        'initiated_by',
        'initiated_at',
        'committed_by',
        'committed_at',
        'failed_at',
        'failure_reason',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'business_date' => 'date',
        'date_from' => 'date',
        'date_to' => 'date',
        'default_category_id' => 'integer',
        'default_supplier_id' => 'integer',
        'default_wallet_id' => 'integer',
        'default_paid' => 'boolean',
        'default_bank_account_id' => 'integer',
        'status' => PettyCashImportStatus::class,
        'revision' => 'integer',
        'stats' => 'array',
        'initiated_by' => 'integer',
        'initiated_at' => 'datetime',
        'committed_by' => 'integer',
        'committed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PettyCashImportRow::class, 'import_batch_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PettyCashImportInvoice::class, 'import_batch_id');
    }

    public function categoryProposals(): HasMany
    {
        return $this->hasMany(PettyCashImportCategoryProposal::class, 'import_batch_id');
    }

    public function editEvents(): HasMany
    {
        return $this->hasMany(PettyCashImportEditEvent::class, 'import_batch_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }

    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'default_category_id');
    }

    public function defaultWallet(): BelongsTo
    {
        return $this->belongsTo(PettyCashWallet::class, 'default_wallet_id');
    }

    public function defaultSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id');
    }

    public function defaultBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'default_bank_account_id');
    }
}

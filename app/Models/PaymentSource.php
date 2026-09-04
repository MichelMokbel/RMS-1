<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PaymentSource extends Model
{
    public const CODE_SKIPCASH = 'skipcash';

    public const METHOD_SKIPCASH = 'skipcash';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'method',
        'clearing_account_id',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'clearing_account_id' => 'integer',
        'is_active' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (PaymentSource $source): void {
            $source->code = strtolower(trim((string) $source->code));
            $source->method = strtolower(trim((string) $source->method));

            $company = AccountingCompany::query()->find($source->company_id);
            $account = LedgerAccount::query()->find($source->clearing_account_id);

            if (! $company || ! $account || (int) $account->company_id !== (int) $company->id) {
                throw ValidationException::withMessages([
                    'clearing_account_id' => __('The payment clearing account must belong to the payment source company.'),
                ]);
            }

            if ($source->is_active && (! $company->is_active || ! $account->is_active)) {
                throw ValidationException::withMessages([
                    'payment_source' => __('An active payment source requires an active company and clearing account.'),
                ]);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function clearingAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'clearing_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentCheckoutAttempt::class, 'payment_source_id');
    }

    public function providerTransactions(): HasMany
    {
        return $this->hasMany(PaymentProviderTransaction::class, 'payment_source_id');
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(PaymentProviderEvent::class, 'payment_source_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'payment_source_id');
    }

    public function settlementImports(): HasMany
    {
        return $this->hasMany(GatewaySettlementImport::class, 'payment_source_id');
    }

    public function clearingSettlements(): HasMany
    {
        return $this->hasMany(ArClearingSettlement::class, 'payment_source_id');
    }
}

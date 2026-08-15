<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrLeaveType extends Model
{
    protected $fillable = ['company_id', 'code', 'name', 'description', 'is_paid', 'is_active'];

    protected $casts = ['company_id' => 'integer', 'is_paid' => 'boolean', 'is_active' => 'boolean'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function policies(): HasMany
    {
        return $this->hasMany(HrLeavePolicy::class, 'leave_type_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(HrLeaveRequest::class, 'leave_type_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(HrLeaveLedgerEntry::class, 'leave_type_id');
    }
}

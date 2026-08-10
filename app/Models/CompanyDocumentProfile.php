<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyDocumentProfile extends Model
{
    protected $fillable = [
        'company_id', 'legal_name_en', 'legal_name_ar', 'address_en', 'address_ar',
        'phone', 'email', 'website', 'commercial_registration', 'tax_registration',
        'brand_color', 'default_terms_en', 'default_terms_ar', 'logo_asset_id',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'logo_asset_id' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(AccountingCompany::class, 'company_id');
    }

    public function logoAsset(): BelongsTo
    {
        return $this->belongsTo(DocumentAsset::class, 'logo_asset_id');
    }
}

<?php

namespace App\Services\Quotations;

use App\Models\AccountingCompany;
use App\Models\CompanyDocumentProfile;
use App\Models\DocumentAsset;
use Illuminate\Validation\ValidationException;

class CompanyDocumentProfileService
{
    public function getOrCreate(AccountingCompany|int $company): CompanyDocumentProfile
    {
        $company = $company instanceof AccountingCompany ? $company : AccountingCompany::query()->findOrFail($company);

        return CompanyDocumentProfile::query()->firstOrCreate(
            ['company_id' => $company->id],
            ['legal_name_en' => $company->name, 'brand_color' => '#1F2937']
        );
    }

    public function update(AccountingCompany|int $company, array $attributes): CompanyDocumentProfile
    {
        $profile = $this->getOrCreate($company);
        if (isset($attributes['logo_asset_id'])) {
            $asset = DocumentAsset::query()->find($attributes['logo_asset_id']);
            if (! $asset || (int) $asset->company_id !== (int) $profile->company_id || $asset->kind !== 'image') {
                throw ValidationException::withMessages(['logo_asset_id' => __('The logo must be an image owned by the same company.')]);
            }
        }
        if (isset($attributes['brand_color']) && ! preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $attributes['brand_color'])) {
            throw ValidationException::withMessages(['brand_color' => __('Brand color must use six-digit hex format.')]);
        }

        $profile->update($attributes);

        return $profile->fresh('logoAsset');
    }
}

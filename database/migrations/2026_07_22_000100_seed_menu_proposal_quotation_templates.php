<?php

use App\Models\AccountingCompany;
use App\Services\Quotations\QuotationTemplateService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_companies')
            || ! Schema::hasTable('document_templates')
            || ! Schema::hasTable('document_template_versions')) {
            return;
        }

        $templates = app(QuotationTemplateService::class);
        AccountingCompany::query()->orderBy('id')->chunkById(100, function ($companies) use ($templates): void {
            foreach ($companies as $company) {
                $templates->ensureDefaultLayoutMetadata($company);
                $templates->ensureMenuProposal($company);
            }
        });
    }

    public function down(): void
    {
        // Seeded templates may have been customized, so rollback intentionally preserves user data.
    }
};

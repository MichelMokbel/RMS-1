<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('kind', 30)->default('image');
            $table->string('disk', 50)->default('s3');
            $table->string('storage_key', 512);
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('accounting_companies')->cascadeOnDelete();
            $table->unique(['disk', 'storage_key']);
            $table->index(['company_id', 'kind']);
        });

        Schema::create('company_document_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('legal_name_en')->nullable();
            $table->string('legal_name_ar')->nullable();
            $table->text('address_en')->nullable();
            $table->text('address_ar')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('commercial_registration', 100)->nullable();
            $table->string('tax_registration', 100)->nullable();
            $table->string('brand_color', 7)->default('#1F2937');
            $table->text('default_terms_en')->nullable();
            $table->text('default_terms_ar')->nullable();
            $table->unsignedBigInteger('logo_asset_id')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('accounting_companies')->cascadeOnDelete();
            $table->foreign('logo_asset_id')->references('id')->on('document_assets')->nullOnDelete();
            $table->unique('company_id');
        });

        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type', 40);
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('accounting_companies')->cascadeOnDelete();
            $table->index(['company_id', 'type', 'is_active']);
            $table->index(['company_id', 'type', 'is_default']);
        });

        Schema::create('document_template_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_template_id');
            $table->unsignedInteger('version');
            $table->unsignedInteger('schema_version')->default(1);
            $table->json('page_settings');
            $table->json('styles');
            $table->json('table_columns');
            $table->json('blocks');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('document_template_id')->references('id')->on('document_templates')->cascadeOnDelete();
            $table->unique(['document_template_id', 'version'], 'document_template_versions_template_version_unique');
        });

        Schema::table('document_templates', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('document_template_versions')->nullOnDelete();
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedInteger('branch_id');
            $table->unsignedInteger('customer_id')->nullable();
            $table->unsignedBigInteger('template_version_id')->nullable();
            $table->unsignedBigInteger('duplicated_from_quotation_id')->nullable();
            $table->string('quotation_number', 40)->nullable();
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('current_revision')->default(0);
            $table->date('issue_date');
            $table->date('valid_until');
            $table->string('currency', 3)->default('QAR');
            $table->string('recipient_name');
            $table->string('recipient_contact_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_phone', 50)->nullable();
            $table->text('recipient_address')->nullable();
            $table->json('page_settings');
            $table->json('styles');
            $table->json('table_columns');
            $table->json('blocks');
            $table->bigInteger('gross_subtotal_cents')->default(0);
            $table->bigInteger('line_discount_total_cents')->default(0);
            $table->bigInteger('subtotal_cents')->default(0);
            $table->string('quotation_discount_type', 20)->nullable();
            $table->bigInteger('quotation_discount_value')->default(0);
            $table->bigInteger('quotation_discount_cents')->default(0);
            $table->bigInteger('discount_total_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('converted_invoice_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('accounting_companies')->restrictOnDelete();
            // Legacy branches use a signed INT primary key; ownership is enforced in domain services.
            $table->foreign('template_version_id')->references('id')->on('document_template_versions')->nullOnDelete();
            $table->foreign('duplicated_from_quotation_id')->references('id')->on('quotations')->nullOnDelete();
            $table->foreign('converted_invoice_id')->references('id')->on('ar_invoices')->nullOnDelete();
            $table->unique(['branch_id', 'quotation_number']);
            $table->index(['company_id', 'status', 'issue_date']);
            $table->index(['branch_id', 'status', 'valid_until']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quotation_id');
            $table->unsignedInteger('menu_item_id')->nullable();
            $table->string('description');
            $table->string('unit', 40)->nullable();
            $table->decimal('quantity', 14, 3);
            $table->bigInteger('unit_price_cents');
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('line_total_cents');
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('catalog_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('quotation_id')->references('id')->on('quotations')->cascadeOnDelete();
            $table->index(['quotation_id', 'sort_order']);
            $table->index('menu_item_id');
        });

        Schema::create('quotation_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quotation_id');
            $table->unsignedInteger('revision');
            $table->string('quotation_number', 40);
            $table->string('status', 30)->default('sent');
            $table->json('snapshot');
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('discount_total_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('finalized_at');
            $table->timestamps();

            $table->foreign('quotation_id')->references('id')->on('quotations')->cascadeOnDelete();
            $table->unique(['quotation_id', 'revision']);
        });

        Schema::create('quotation_artifacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quotation_version_id');
            $table->string('format', 10);
            $table->string('disk', 50)->default('s3');
            $table->string('storage_key', 512)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->string('generation_status', 20)->default('pending');
            $table->timestamp('generated_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('quotation_version_id')->references('id')->on('quotation_versions')->cascadeOnDelete();
            $table->unique(['quotation_version_id', 'format']);
            $table->index(['generation_status', 'created_at']);
        });

        Schema::create('quotation_status_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quotation_id');
            $table->unsignedBigInteger('quotation_version_id')->nullable();
            $table->string('event', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('quotation_id')->references('id')->on('quotations')->cascadeOnDelete();
            $table->foreign('quotation_version_id')->references('id')->on('quotation_versions')->nullOnDelete();
            $table->index(['quotation_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('quotation_status_events');
        Schema::dropIfExists('quotation_artifacts');
        Schema::dropIfExists('quotation_versions');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('document_template_versions');
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('company_document_profiles');
        Schema::dropIfExists('document_assets');
    }
};

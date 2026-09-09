<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->uuid('event_uuid');
            $table->char('journey_hash', 64);
            $table->string('event_name', 40);
            $table->string('source', 20)->default('browser');
            $table->dateTime('received_at', 6);
            $table->unsignedBigInteger('profile_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('channel_code', 20)->nullable();
            $table->string('path_code', 30)->nullable();
            $table->string('source_section', 30)->nullable();
            $table->unsignedSmallInteger('result_count')->nullable();
            $table->unsignedSmallInteger('query_length')->nullable();
            $table->string('quantity_bucket', 20)->nullable();
            $table->unsignedSmallInteger('line_count')->nullable();
            $table->unsignedSmallInteger('lead_day_count')->nullable();
            $table->timestamps(6);

            $table->unique('event_uuid', 'storefront_events_uuid_unique');
            $table->index(['company_id', 'received_at', 'event_name'], 'storefront_events_report_idx');
            $table->foreign('company_id', 'storefront_events_company_fk')
                ->references('id')->on('accounting_companies')->cascadeOnDelete();
            $table->foreign('profile_id', 'storefront_events_profile_fk')
                ->references('id')->on('storefront_item_profiles')->nullOnDelete();
            $table->foreign('category_id', 'storefront_events_category_fk')
                ->references('id')->on('storefront_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_events');
    }
};

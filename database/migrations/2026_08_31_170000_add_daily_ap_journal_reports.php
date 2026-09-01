<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subledger_entries', function (Blueprint $table) {
            $table->index(['company_id', 'entry_date', 'source_type', 'status'], 'ap_journal_daily_lookup');
        });
        Schema::table('finance_settings', function (Blueprint $table) {
            $table->boolean('ap_report_enabled')->default(false);
            $table->string('ap_report_email')->nullable();
            $table->foreignId('ap_report_company_id')->nullable()->constrained('accounting_companies')->restrictOnDelete();
        });

        Schema::create('ap_daily_journal_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('accounting_companies')->restrictOnDelete();
            $table->date('report_date');
            $table->json('snapshot');
            $table->unsignedBigInteger('entry_count')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('generated_at');
            $table->string('email_status')->default('pending');
            $table->string('recipient')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('emailed_revision')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'report_date'], 'ap_daily_journal_company_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_daily_journal_reports');
        Schema::table('subledger_entries', function (Blueprint $table) {
            $table->dropIndex('ap_journal_daily_lookup');
        });
        Schema::table('finance_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ap_report_company_id');
            $table->dropColumn(['ap_report_enabled', 'ap_report_email']);
        });
    }
};

<?php

use App\Services\Sequences\DocumentSequenceService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The shared allocator's random fallback must never be used for these documents.
        if (! Schema::hasTable('document_sequences')) {
            throw new RuntimeException('Document sequences must be installed before numbering AP reports.');
        }

        if (! Schema::hasColumn('ap_daily_journal_reports', 'document_number')) {
            Schema::table('ap_daily_journal_reports', function (Blueprint $table) {
                $table->string('document_number', 32)->nullable();
            });
        }

        DB::table('accounting_companies')->select('id')->orderBy('id')->chunkById(100, function ($companies) {
            foreach ($companies as $company) {
                do {
                    $count = DB::transaction(function () use ($company) {
                        // Match generation's locking order. Each batch commits numbers and counters together.
                        DB::table('accounting_companies')->where('id', $company->id)->lockForUpdate()->first();
                        $reports = DB::table('ap_daily_journal_reports')->where('company_id', $company->id)
                            ->whereNull('document_number')->orderBy('report_date')->orderBy('id')->limit(200)->lockForUpdate()->get();

                        foreach ($reports as $report) {
                            $year = substr($report->report_date, 0, 4);
                            $sequence = app(DocumentSequenceService::class)->next('ap_daily_journal', (int) $company->id, $year);
                            DB::table('ap_daily_journal_reports')->where('id', $report->id)->update([
                                'document_number' => sprintf('APJ-%s-%04d', $year, $sequence),
                            ]);
                        }

                        return $reports->count();
                    });
                } while ($count > 0);
            }
        });

        Schema::table('ap_daily_journal_reports', function (Blueprint $table) {
            $table->string('document_number', 32)->nullable(false)->change();
        });
        if (! Schema::hasIndex('ap_daily_journal_reports', 'ap_daily_journal_company_number_unique')) {
            Schema::table('ap_daily_journal_reports', function (Blueprint $table) {
                $table->unique(['company_id', 'document_number'], 'ap_daily_journal_company_number_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('ap_daily_journal_reports', function (Blueprint $table) {
            $table->dropUnique('ap_daily_journal_company_number_unique');
            $table->dropColumn('document_number');
        });
        // Retain sequence counters so a later deployment cannot reuse previously issued numbers.
    }
};

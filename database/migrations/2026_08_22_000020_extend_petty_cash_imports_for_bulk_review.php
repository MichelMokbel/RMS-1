<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_import_batches', function (Blueprint $table): void {
            $table->string('import_mode', 20)->default('daily')->after('company_id');
            $table->date('date_from')->nullable()->after('business_date');
            $table->date('date_to')->nullable()->after('date_from');
            $table->integer('default_supplier_id')->nullable()->after('default_category_id');
            $table->boolean('default_paid')->nullable()->after('default_wallet_id');
            $table->unsignedInteger('revision')->default(0)->after('status');
            $table->string('parser_version', 30)->default('1')->after('revision');
            $table->index(['company_id', 'import_mode', 'date_from'], 'pc_import_company_mode_date_idx');
        });

        Schema::table('petty_cash_import_invoices', function (Blueprint $table): void {
            $table->dropUnique('pc_import_invoice_batch_entry_unique');
            $table->date('business_date')->nullable()->after('entry_id');
            $table->boolean('excluded')->default(false)->after('status');
            $table->longText('original_header')->nullable()->after('header');
            $table->unique(
                ['import_batch_id', 'business_date', 'entry_id'],
                'pc_import_invoice_batch_date_entry_unique'
            );
            $table->index(['import_batch_id', 'business_date'], 'pc_import_invoice_batch_date_idx');
        });

        Schema::table('petty_cash_import_rows', function (Blueprint $table): void {
            $table->boolean('excluded')->default(false)->after('status');
            $table->longText('original_payload')->nullable()->after('payload');
        });

        DB::table('petty_cash_import_batches')->update([
            'date_from' => DB::raw('business_date'),
            'date_to' => DB::raw('business_date'),
        ]);
        DB::table('petty_cash_import_invoices')->chunkById(200, function ($invoices): void {
            $dates = DB::table('petty_cash_import_batches')
                ->whereIn('id', $invoices->pluck('import_batch_id')->unique())
                ->pluck('business_date', 'id');
            foreach ($invoices as $invoice) {
                DB::table('petty_cash_import_invoices')->where('id', $invoice->id)->update([
                    'business_date' => $dates[$invoice->import_batch_id] ?? null,
                    'original_header' => DB::raw('header'),
                ]);
            }
        });
        DB::table('petty_cash_import_rows')->whereNull('original_payload')->update([
            'original_payload' => DB::raw('payload'),
        ]);

        Schema::create('petty_cash_import_category_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('petty_cash_import_batches')->restrictOnDelete();
            $table->string('source_code', 100)->nullable();
            $table->string('source_name', 100);
            $table->string('normalized_name', 100);
            $table->boolean('is_declared')->default(false);
            $table->string('status', 30)->default('proposed');
            $table->integer('expense_category_id')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'normalized_name'], 'pc_import_category_batch_name_unique');
            $table->index(['import_batch_id', 'status'], 'pc_import_category_batch_status_idx');
        });

        Schema::create('petty_cash_import_edit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('petty_cash_import_batches')->restrictOnDelete();
            $table->foreignId('import_invoice_id')->nullable()->constrained('petty_cash_import_invoices')->restrictOnDelete();
            $table->foreignId('import_row_id')->nullable()->constrained('petty_cash_import_rows')->restrictOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 60);
            $table->longText('before_values')->nullable();
            $table->longText('after_values')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['import_batch_id', 'created_at'], 'pc_import_edit_batch_created_idx');
        });
    }

    /** Import audit and lineage records are intentionally retained. */
    public function down(): void
    {
        // No-op.
    }
};

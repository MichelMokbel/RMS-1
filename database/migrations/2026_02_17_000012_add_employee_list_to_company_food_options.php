<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_food_options', 'employee_list_id')) {
            Schema::table('company_food_options', function (Blueprint $table): void {
                $table->foreignId('employee_list_id')->nullable()->after('project_id')->constrained('company_food_employee_lists')->cascadeOnDelete();
            });
        }

        // Backfill: assign existing options to the project's first (default) list
        $options = DB::table('company_food_options')->whereNull('employee_list_id')->get();
        foreach ($options as $opt) {
            $listId = DB::table('company_food_employee_lists')
                ->where('project_id', $opt->project_id)
                ->orderBy('sort_order')
                ->value('id');
            if ($listId) {
                DB::table('company_food_options')->where('id', $opt->id)->update(['employee_list_id' => $listId]);
            }
        }

        if (Schema::hasColumn('company_food_options', 'employee_list_id')) {
            DB::statement('ALTER TABLE company_food_options MODIFY employee_list_id BIGINT UNSIGNED NOT NULL');
        }

        $indexExists = DB::select("SHOW KEYS FROM company_food_options WHERE Key_name = 'cf_options_project_list_date_cat_idx'");
        if (empty($indexExists)) {
            Schema::table('company_food_options', function (Blueprint $table): void {
                $table->index(['project_id', 'employee_list_id', 'menu_date', 'category'], 'cf_options_project_list_date_cat_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('company_food_options', function (Blueprint $table): void {
            $table->dropIndex('cf_options_project_list_date_cat_idx');
            $table->dropForeign(['employee_list_id']);
        });
    }
};

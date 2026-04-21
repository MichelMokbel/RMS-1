<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_food_options', 'menu_date')) {
            Schema::table('company_food_options', function (Blueprint $table): void {
                $table->date('menu_date')->nullable()->after('project_id');
            });
        }

        // Backfill: set menu_date to project start_date for existing options
        $options = DB::table('company_food_options')->get();
        foreach ($options as $opt) {
            $startDate = DB::table('company_food_projects')->where('id', $opt->project_id)->value('start_date');
            if ($startDate) {
                DB::table('company_food_options')->where('id', $opt->id)->update(['menu_date' => $startDate]);
            }
        }

        Schema::table('company_food_options', function (Blueprint $table): void {
            $table->date('menu_date')->nullable(false)->change();
        });

        $indexExists = DB::select("SHOW KEYS FROM company_food_options WHERE Key_name = 'company_food_options_project_id_menu_date_category_index'");
        if (empty($indexExists)) {
            Schema::table('company_food_options', function (Blueprint $table): void {
                $table->index(['project_id', 'menu_date', 'category']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('company_food_options', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'menu_date', 'category']);
            $table->dropColumn('menu_date');
        });
    }
};

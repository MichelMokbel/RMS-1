<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_food_employees', function (Blueprint $table): void {
            $table->foreignId('employee_list_id')->nullable()->after('project_id')->constrained('company_food_employee_lists')->cascadeOnDelete();
        });

        // Backfill: create default List 1 for each project and assign employees
        $projects = DB::table('company_food_projects')->get();
        foreach ($projects as $project) {
            $listId = DB::table('company_food_employee_lists')->insertGetId([
                'project_id' => $project->id,
                'name' => 'List 1',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (['salad', 'appetizer', 'main', 'sweet', 'location'] as $i => $category) {
                DB::table('company_food_list_categories')->insert([
                    'employee_list_id' => $listId,
                    'category' => $category,
                    'sort_order' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('company_food_employees')
                ->where('project_id', $project->id)
                ->update(['employee_list_id' => $listId]);
        }

        Schema::table('company_food_employees', function (Blueprint $table): void {
            $table->foreignId('employee_list_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('company_food_employees', function (Blueprint $table): void {
            $table->dropForeign(['employee_list_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_food_orders', function (Blueprint $table): void {
            $table->foreignId('employee_list_id')->nullable()->after('project_id')->constrained('company_food_employee_lists')->cascadeOnDelete();
            $table->foreignId('soup_option_id')->nullable()->after('location_option_id')->constrained('company_food_options')->nullOnDelete();
        });

        // Backfill employee_list_id: use the project's first (default) list
        $orders = DB::table('company_food_orders')->get();
        foreach ($orders as $order) {
            $listId = DB::table('company_food_employee_lists')
                ->where('project_id', $order->project_id)
                ->orderBy('sort_order')
                ->value('id');
            if ($listId) {
                DB::table('company_food_orders')
                    ->where('id', $order->id)
                    ->update(['employee_list_id' => $listId]);
            }
        }

        Schema::table('company_food_orders', function (Blueprint $table): void {
            $table->foreignId('employee_list_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('company_food_orders', function (Blueprint $table): void {
            $table->dropForeign(['employee_list_id']);
            $table->dropForeign(['soup_option_id']);
        });
    }
};

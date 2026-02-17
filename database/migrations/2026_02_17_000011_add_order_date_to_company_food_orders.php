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
            $table->date('order_date')->nullable()->after('employee_list_id');
        });

        // Backfill: set order_date to project start_date for existing orders
        $orders = DB::table('company_food_orders')->get();
        foreach ($orders as $order) {
            $startDate = DB::table('company_food_projects')->where('id', $order->project_id)->value('start_date');
            if ($startDate) {
                DB::table('company_food_orders')->where('id', $order->id)->update(['order_date' => $startDate]);
            }
        }

        Schema::table('company_food_orders', function (Blueprint $table): void {
            $table->date('order_date')->nullable(false)->change();
            $table->index(['project_id', 'order_date']);
        });
    }

    public function down(): void
    {
        Schema::table('company_food_orders', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'order_date']);
            $table->dropColumn('order_date');
        });
    }
};

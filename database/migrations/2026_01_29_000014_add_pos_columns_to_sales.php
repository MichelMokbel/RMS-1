<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'order_type')) {
                $table->string('order_type', 20)->nullable()->after('status'); // takeaway|dine_in
            }
            if (! Schema::hasColumn('sales', 'reference')) {
                $table->string('reference', 255)->nullable()->after('notes');
            }
            if (! Schema::hasColumn('sales', 'global_discount_cents')) {
                $table->bigInteger('global_discount_cents')->default(0)->after('discount_total_cents');
            }
            if (! Schema::hasColumn('sales', 'held_at')) {
                $table->timestamp('held_at')->nullable()->after('due_total_cents');
            }
            if (! Schema::hasColumn('sales', 'held_by')) {
                $table->unsignedBigInteger('held_by')->nullable()->after('held_at');
            }
            if (! Schema::hasColumn('sales', 'kot_printed_at')) {
                $table->timestamp('kot_printed_at')->nullable()->after('held_by');
            }
            if (! Schema::hasColumn('sales', 'kot_printed_by')) {
                $table->unsignedBigInteger('kot_printed_by')->nullable()->after('kot_printed_at');
            }
        });

        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'note')) {
                $table->string('note', 500)->nullable()->after('meta');
            }
            if (! Schema::hasColumn('sale_items', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn([
                'order_type', 'reference', 'global_discount_cents',
                'held_at', 'held_by', 'kot_printed_at', 'kot_printed_by',
            ]);
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['note', 'sort_order']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recipes') || Schema::hasColumn('recipes', 'status')) {
            return;
        }

        Schema::table('recipes', function (Blueprint $table) {
            $table->string('status', 20)->default('published')->after('selling_price_per_unit');
            $table->index('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('recipes') || ! Schema::hasColumn('recipes', 'status')) {
            return;
        }

        Schema::table('recipes', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('clearing_settled_at')->nullable()->after('voided_at');
            $table->index(['method', 'clearing_settled_at', 'voided_at'], 'idx_payments_cleared');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_cleared');
            $table->dropColumn('clearing_settled_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ap_payments', function (Blueprint $table) {
            $table->timestamp('cheque_cleared_at')->nullable()->after('voided_at');
            $table->index(['payment_method', 'cheque_cleared_at', 'voided_at'], 'idx_ap_payments_cleared');
        });
    }

    public function down(): void
    {
        Schema::table('ap_payments', function (Blueprint $table) {
            $table->dropIndex('idx_ap_payments_cleared');
            $table->dropColumn('cheque_cleared_at');
        });
    }
};

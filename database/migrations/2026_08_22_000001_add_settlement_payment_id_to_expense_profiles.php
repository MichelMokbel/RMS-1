<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_profiles', function (Blueprint $table) {
            // ap_payments is a legacy table whose primary key is a signed INT.
            $table->integer('settlement_payment_id')
                ->nullable()
                ->after('settlement_mode');
            $table->index('settlement_payment_id', 'expense_profiles_settlement_payment_idx');
            $table->foreign('settlement_payment_id', 'expense_profiles_settlement_payment_fk')
                ->references('id')
                ->on('ap_payments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expense_profiles', function (Blueprint $table) {
            $table->dropForeign('expense_profiles_settlement_payment_fk');
            $table->dropIndex('expense_profiles_settlement_payment_idx');
            $table->dropColumn('settlement_payment_id');
        });
    }
};

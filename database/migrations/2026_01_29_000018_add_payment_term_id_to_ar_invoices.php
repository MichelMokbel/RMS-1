<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ar_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('ar_invoices', 'payment_term_id')) {
                $table->unsignedBigInteger('payment_term_id')->nullable()->after('payment_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ar_invoices', function (Blueprint $table) {
            $table->dropColumn(['payment_term_id']);
        });
    }
};

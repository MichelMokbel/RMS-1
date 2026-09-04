<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_checkout_attempts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('provider_detail_recovery_attempts')
                ->default(0)
                ->after('last_error_code');
        });
    }

    public function down(): void
    {
        Schema::table('payment_checkout_attempts', function (Blueprint $table): void {
            $table->dropColumn('provider_detail_recovery_attempts');
        });
    }
};

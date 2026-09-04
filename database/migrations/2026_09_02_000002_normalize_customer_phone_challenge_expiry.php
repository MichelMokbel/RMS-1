<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_phone_verification_challenges')) {
            return;
        }

        DB::statement(
            'ALTER TABLE `customer_phone_verification_challenges` MODIFY `expires_at` DATETIME NOT NULL'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_phone_verification_challenges')) {
            return;
        }

        DB::statement(
            'ALTER TABLE `customer_phone_verification_challenges` MODIFY `expires_at` TIMESTAMP NOT NULL'
        );
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Add email snapshot for customer-form submissions
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'customer_email_snapshot')) {
                $table->string('customer_email_snapshot', 255)->nullable()->after('customer_phone_snapshot');
            }
        });

        // Allow public-created orders (no internal user) + add Website source option.
        // This project schema comes from a MySQL dump; use raw SQL for enum + nullability on MySQL only.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `orders` MODIFY `source` ENUM('POS','Phone','WhatsApp','Subscription','Backoffice','Website') NOT NULL DEFAULT 'Backoffice'");
            DB::statement("ALTER TABLE `orders` MODIFY `created_by` int(11) NULL");
        }
    }

    public function down(): void
    {
        // Best-effort rollback (keep email column; reverting enums safely depends on existing data).
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `orders` MODIFY `created_by` int(11) NOT NULL");
            DB::statement("ALTER TABLE `orders` MODIFY `source` ENUM('POS','Phone','WhatsApp','Subscription','Backoffice') NOT NULL DEFAULT 'Backoffice'");
        }
    }
};



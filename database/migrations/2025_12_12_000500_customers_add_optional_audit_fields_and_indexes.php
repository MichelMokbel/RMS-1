<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'created_by')) {
                $table->integer('created_by')->nullable()->after('notes');
            }

            if (! Schema::hasColumn('customers', 'updated_by')) {
                $table->integer('updated_by')->nullable()->after('created_by');
            }

            $table->index('customer_type', 'customers_customer_type_index');
            $table->index('is_active', 'customers_is_active_index');
            if (! $this->hasIndex('customers', 'customers_phone_index')) {
                $table->index('phone', 'customers_phone_index');
            }
            if (! $this->hasIndex('customers', 'customers_email_index')) {
                $table->index('email', 'customers_email_index');
            }
        });

        if (Schema::hasTable('users')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
                $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                if ($this->hasForeign('customers', 'customers_created_by_foreign')) {
                    $table->dropForeign('customers_created_by_foreign');
                }
                if ($this->hasForeign('customers', 'customers_updated_by_foreign')) {
                    $table->dropForeign('customers_updated_by_foreign');
                }
                if ($this->hasIndex('customers', 'customers_customer_type_index')) {
                    $table->dropIndex('customers_customer_type_index');
                }
                if ($this->hasIndex('customers', 'customers_is_active_index')) {
                    $table->dropIndex('customers_is_active_index');
                }
                if ($this->hasIndex('customers', 'customers_phone_index')) {
                    $table->dropIndex('customers_phone_index');
                }
                if ($this->hasIndex('customers', 'customers_email_index')) {
                    $table->dropIndex('customers_email_index');
                }
                if (Schema::hasColumn('customers', 'updated_by')) {
                    $table->dropColumn('updated_by');
                }
                if (Schema::hasColumn('customers', 'created_by')) {
                    $table->dropColumn('created_by');
                }
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        $rows = DB::select('SHOW INDEX FROM '.$table);
        foreach ($rows as $row) {
            if (isset($row->Key_name) && $row->Key_name === $index) {
                return true;
            }
        }
        return false;
    }

    private function hasForeign(string $table, string $foreign): bool
    {
        $rows = DB::select('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ?', [$table, 'FOREIGN KEY']);
        foreach ($rows as $row) {
            if (isset($row->CONSTRAINT_NAME) && $row->CONSTRAINT_NAME === $foreign) {
                return true;
            }
        }
        return false;
    }
};

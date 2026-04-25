<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'portal_name')) {
                $table->string('portal_name')->nullable()->after('customer_id');
            }
            if (! Schema::hasColumn('users', 'portal_phone')) {
                $table->string('portal_phone', 50)->nullable()->after('portal_name');
            }
            if (! Schema::hasColumn('users', 'portal_phone_e164')) {
                $table->string('portal_phone_e164', 20)->nullable()->after('portal_phone');
            }
            if (! Schema::hasColumn('users', 'portal_delivery_address')) {
                $table->text('portal_delivery_address')->nullable()->after('portal_phone_e164');
            }
            if (! Schema::hasColumn('users', 'portal_phone_verified_at')) {
                $table->timestamp('portal_phone_verified_at')->nullable()->after('portal_delivery_address');
            }
        });

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table): void {
                if (! Schema::hasColumn('orders', 'user_id')) {
                    $table->integer('user_id')->nullable()->after('customer_id');
                }
            });

            $this->ensureIndex('orders', 'orders_user_id_index', 'ALTER TABLE `orders` ADD INDEX `orders_user_id_index` (`user_id`)');
            $this->ensureForeignKey(
                'orders',
                'orders_user_id_foreign',
                'ALTER TABLE `orders` ADD CONSTRAINT `orders_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL'
            );
        }

        if (Schema::hasTable('customer_phone_verification_challenges') && Schema::hasColumn('customer_phone_verification_challenges', 'customer_id')) {
            DB::statement('ALTER TABLE `customer_phone_verification_challenges` MODIFY `customer_id` INT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'user_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropForeign('orders_user_id_foreign');
                $table->dropIndex('orders_user_id_index');
                $table->dropColumn('user_id');
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            foreach ([
                'portal_phone_verified_at',
                'portal_delivery_address',
                'portal_phone_e164',
                'portal_phone',
                'portal_name',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function ensureIndex(string $table, string $indexName, string $statement): void
    {
        if ($this->indexExists($table, $indexName)) {
            return;
        }

        DB::statement($statement);
    }

    private function ensureForeignKey(string $table, string $constraintName, string $statement): void
    {
        if ($this->foreignKeyExists($table, $constraintName)) {
            return;
        }

        DB::statement($statement);
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraintName)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureSignedIntColumn('users', 'customer_id', 'email');
        $this->ensureIndex('users', 'users_customer_id_unique', 'ALTER TABLE `users` ADD UNIQUE `users_customer_id_unique` (`customer_id`)');
        $this->ensureForeignKey(
            'users',
            'users_customer_id_foreign',
            'ALTER TABLE `users` ADD CONSTRAINT `users_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL'
        );

        Schema::table('customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers', 'phone_e164')) {
                $table->string('phone_e164', 20)->nullable()->after('phone');
            }

            if (! Schema::hasColumn('customers', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('phone_e164');
            }
        });
        $this->ensureIndex('customers', 'customers_phone_e164_index', 'ALTER TABLE `customers` ADD INDEX `customers_phone_e164_index` (`phone_e164`)');

        $this->ensureSignedIntColumn('meal_plan_requests', 'customer_id', 'id');
        $this->ensureSignedIntColumn('meal_plan_requests', 'user_id', 'customer_id');
        $this->ensureIndex('meal_plan_requests', 'meal_plan_requests_customer_id_index', 'ALTER TABLE `meal_plan_requests` ADD INDEX `meal_plan_requests_customer_id_index` (`customer_id`)');
        $this->ensureIndex('meal_plan_requests', 'meal_plan_requests_user_id_index', 'ALTER TABLE `meal_plan_requests` ADD INDEX `meal_plan_requests_user_id_index` (`user_id`)');
        $this->ensureForeignKey(
            'meal_plan_requests',
            'meal_plan_requests_customer_id_foreign',
            'ALTER TABLE `meal_plan_requests` ADD CONSTRAINT `meal_plan_requests_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL'
        );
        $this->ensureForeignKey(
            'meal_plan_requests',
            'meal_plan_requests_user_id_foreign',
            'ALTER TABLE `meal_plan_requests` ADD CONSTRAINT `meal_plan_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL'
        );

        if (! Schema::hasTable('customer_phone_verification_challenges')) {
            Schema::create('customer_phone_verification_challenges', function (Blueprint $table): void {
                $table->id();
                $table->integer('user_id');
                $table->integer('customer_id');
                $table->string('purpose', 30);
                $table->string('phone_e164', 20);
                $table->string('code_hash');
                $table->timestamp('expires_at');
                $table->unsignedTinyInteger('attempt_count')->default(0);
                $table->unsignedTinyInteger('send_count')->default(0);
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('provider', 50)->nullable();
                $table->string('provider_message_id')->nullable();
                $table->string('request_ip', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();

                $table->foreign('user_id', 'customer_phone_verification_challenges_user_id_foreign')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
                $table->foreign('customer_id', 'customer_phone_verification_challenges_customer_id_foreign')
                    ->references('id')
                    ->on('customers')
                    ->cascadeOnDelete();

                $table->index(['user_id', 'purpose'], 'customer_phone_verification_challenges_user_purpose_index');
                $table->index(['customer_id', 'purpose'], 'customer_phone_verification_challenges_customer_purpose_index');
                $table->index('phone_e164', 'customer_phone_verification_challenges_phone_e164_index');
            });
        }

        $defaultCountryCode = (string) env('CUSTOMERS_DEFAULT_COUNTRY_CODE', '+974');
        $localPhoneLength = max(1, (int) env('CUSTOMERS_LOCAL_PHONE_LENGTH', 8));
        $countryDigits = preg_replace('/\D+/', '', $defaultCountryCode) ?: '974';

        DB::table('customers')
            ->select(['id', 'phone'])
            ->orderBy('id')
            ->chunkById(200, function ($customers) use ($countryDigits, $localPhoneLength): void {
                foreach ($customers as $customer) {
                    $phone = trim((string) ($customer->phone ?? ''));
                    if ($phone === '') {
                        continue;
                    }

                    $normalized = preg_replace('/[^\d+]+/', '', $phone) ?? '';
                    if (str_starts_with($normalized, '00')) {
                        $normalized = '+'.substr($normalized, 2);
                    }

                    if (! str_starts_with($normalized, '+')) {
                        $digits = preg_replace('/\D+/', '', $normalized) ?? '';
                        $digits = ltrim($digits, '0');

                        if ($digits === '') {
                            continue;
                        }

                        $normalized = strlen($digits) <= $localPhoneLength
                            ? '+'.$countryDigits.$digits
                            : '+'.$digits;
                    } else {
                        $digits = preg_replace('/\D+/', '', substr($normalized, 1)) ?? '';
                        if ($digits === '') {
                            continue;
                        }

                        $normalized = '+'.$digits;
                    }

                    DB::table('customers')
                        ->where('id', $customer->id)
                        ->update(['phone_e164' => $normalized]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_phone_verification_challenges');

        Schema::table('meal_plan_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('meal_plan_requests', 'user_id')) {
                $table->dropForeign('meal_plan_requests_user_id_foreign');
                $table->dropIndex('meal_plan_requests_user_id_index');
                $table->dropColumn('user_id');
            }

            if (Schema::hasColumn('meal_plan_requests', 'customer_id')) {
                $table->dropForeign('meal_plan_requests_customer_id_foreign');
                $table->dropIndex('meal_plan_requests_customer_id_index');
                $table->dropColumn('customer_id');
            }
        });

        Schema::table('customers', function (Blueprint $table): void {
            if (Schema::hasColumn('customers', 'phone_verified_at')) {
                $table->dropColumn('phone_verified_at');
            }

            if (Schema::hasColumn('customers', 'phone_e164')) {
                $table->dropIndex('customers_phone_e164_index');
                $table->dropColumn('phone_e164');
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'customer_id')) {
                $table->dropForeign('users_customer_id_foreign');
                $table->dropUnique('users_customer_id_unique');
                $table->dropColumn('customer_id');
            }
        });
    }

    private function ensureSignedIntColumn(string $table, string $column, string $afterColumn): void
    {
        if (! Schema::hasColumn($table, $column)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $afterColumn): void {
                $blueprint->integer($column)->nullable()->after($afterColumn);
            });

            return;
        }

        $type = strtolower((string) $this->columnType($table, $column));
        if (str_contains($type, 'unsigned')) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` MODIFY `%s` INT NULL',
                $table,
                $column
            ));
        }
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

    private function columnType(string $table, string $column): ?string
    {
        $result = DB::table('information_schema.columns')
            ->select('column_type')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->first();

        return $result?->column_type ? (string) $result->column_type : null;
    }
};

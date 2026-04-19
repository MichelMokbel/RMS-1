<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ap_payments')) {
            Schema::table('ap_payments', function (Blueprint $table) {
                if (! Schema::hasColumn('ap_payments', 'client_uuid')) {
                    $table->char('client_uuid', 36)->nullable()->after('supplier_id');
                }
            });

            if (! $this->indexExists('ap_payments', 'ap_payments_client_uuid_unique')) {
                if ($this->hasDuplicateApPaymentClientUuids()) {
                    throw new RuntimeException('Cannot add ap_payments_client_uuid_unique: duplicate AP payment client_uuid values already exist.');
                }

                Schema::table('ap_payments', function (Blueprint $table) {
                    $table->unique(['client_uuid'], 'ap_payments_client_uuid_unique');
                });
            }
        }

        if (Schema::hasTable('bank_transactions') && ! $this->indexExists('bank_transactions', 'bank_transactions_source_unique')) {
            if ($this->hasDuplicateBankTransactionSources()) {
                throw new RuntimeException('Cannot add bank_transactions_source_unique: duplicate bank transaction source rows already exist.');
            }

            Schema::table('bank_transactions', function (Blueprint $table) {
                $table->unique(['source_type', 'source_id', 'transaction_type'], 'bank_transactions_source_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bank_transactions') && $this->indexExists('bank_transactions', 'bank_transactions_source_unique')) {
            Schema::table('bank_transactions', function (Blueprint $table) {
                $table->dropUnique('bank_transactions_source_unique');
            });
        }

        if (Schema::hasTable('ap_payments')) {
            $hasClientUuid = Schema::hasColumn('ap_payments', 'client_uuid');
            $hasClientUuidIndex = $this->indexExists('ap_payments', 'ap_payments_client_uuid_unique');

            Schema::table('ap_payments', function (Blueprint $table) use ($hasClientUuid, $hasClientUuidIndex) {
                if ($hasClientUuidIndex) {
                    $table->dropUnique('ap_payments_client_uuid_unique');
                }
                if ($hasClientUuid) {
                    $table->dropColumn('client_uuid');
                }
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return false;
        }

        $row = DB::selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $index]
        );

        return $row !== null;
    }

    private function hasDuplicateApPaymentClientUuids(): bool
    {
        return DB::table('ap_payments')
            ->select('client_uuid')
            ->whereNotNull('client_uuid')
            ->groupBy('client_uuid')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    private function hasDuplicateBankTransactionSources(): bool
    {
        return DB::table('bank_transactions')
            ->select(['source_type', 'source_id', 'transaction_type'])
            ->whereNotNull('source_type')
            ->whereNotNull('source_id')
            ->whereNotNull('transaction_type')
            ->groupBy('source_type', 'source_id', 'transaction_type')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ap_cheque_clearances')) {
            Schema::create('ap_cheque_clearances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('bank_account_id');
                // Normalized to the actual ap_payments.id type right after create.
                $table->integer('ap_payment_id');
                $table->date('clearance_date');
                $table->decimal('amount', 15, 2);
                $table->char('client_uuid', 36)->nullable();
                $table->string('reference')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('void_reason')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'clearance_date']);
                $table->index('ap_payment_id');
            });
        }

        $this->normalizeApPaymentIdColumnType();
        $this->addApPaymentForeignKeyIfCompatible();

        if (DB::getDriverName() === 'mysql') {
            $this->ensureActiveSentinelIndex();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_cheque_clearances');
    }

    private function normalizeApPaymentIdColumnType(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $targetType = $this->mysqlApPaymentsIdColumnType();
        if (! $targetType) {
            return;
        }

        DB::statement("ALTER TABLE ap_cheque_clearances MODIFY ap_payment_id {$targetType} NOT NULL");
    }

    private function addApPaymentForeignKeyIfCompatible(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable('ap_payments') || ! Schema::hasTable('ap_cheque_clearances')) {
            return;
        }

        if ($this->foreignKeyExists('ap_cheque_clearances_ap_payment_id_foreign')) {
            return;
        }

        try {
            DB::statement(
                'ALTER TABLE ap_cheque_clearances
                 ADD CONSTRAINT ap_cheque_clearances_ap_payment_id_foreign
                 FOREIGN KEY (ap_payment_id) REFERENCES ap_payments(id)'
            );
        } catch (\Throwable $e) {
            Log::warning('Skipping ap_cheque_clearances FK creation: '.$e->getMessage());
        }
    }

    private function ensureActiveSentinelIndex(): void
    {
        $legacyUuidUnique = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ap_cheque_clearances'
               AND INDEX_NAME = 'ap_cheque_clearances_client_uuid_unique'"
        );

        if ((int) ($legacyUuidUnique->c ?? 0) > 0) {
            DB::statement('ALTER TABLE ap_cheque_clearances DROP INDEX ap_cheque_clearances_client_uuid_unique');
        }

        $hasSentinel = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ap_cheque_clearances'
               AND COLUMN_NAME = 'active_sentinel'"
        );

        if ((int) ($hasSentinel->c ?? 0) === 0) {
            DB::statement('ALTER TABLE ap_cheque_clearances ADD COLUMN active_sentinel TINYINT AS (IF(voided_at IS NULL, 1, NULL)) STORED');
        }

        $hasActiveClientUuid = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ap_cheque_clearances'
               AND COLUMN_NAME = 'active_client_uuid'"
        );

        if ((int) ($hasActiveClientUuid->c ?? 0) === 0) {
            DB::statement('ALTER TABLE ap_cheque_clearances ADD COLUMN active_client_uuid CHAR(36) AS (IF(voided_at IS NULL, client_uuid, NULL)) STORED');
        }

        $hasIndex = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ap_cheque_clearances'
               AND INDEX_NAME = 'uq_apc_payment_active'"
        );

        if ((int) ($hasIndex->c ?? 0) === 0) {
            DB::statement('ALTER TABLE ap_cheque_clearances ADD UNIQUE INDEX uq_apc_payment_active (ap_payment_id, active_sentinel)');
        }

        $hasClientUuidIndex = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ap_cheque_clearances'
               AND INDEX_NAME = 'uq_apc_client_uuid_active'"
        );

        if ((int) ($hasClientUuidIndex->c ?? 0) === 0) {
            DB::statement('ALTER TABLE ap_cheque_clearances ADD UNIQUE INDEX uq_apc_client_uuid_active (active_client_uuid)');
        }
    }

    private function mysqlApPaymentsIdColumnType(): ?string
    {
        $row = DB::selectOne(
            "SELECT COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ap_payments'
               AND COLUMN_NAME = 'id'
             LIMIT 1"
        );

        $columnType = strtolower((string) ($row->COLUMN_TYPE ?? ''));

        return $columnType !== '' ? $columnType : null;
    }

    private function foreignKeyExists(string $name): bool
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ap_cheque_clearances'
               AND CONSTRAINT_TYPE = 'FOREIGN KEY'
               AND CONSTRAINT_NAME = ?",
            [$name]
        );

        return (int) ($row->c ?? 0) > 0;
    }
};

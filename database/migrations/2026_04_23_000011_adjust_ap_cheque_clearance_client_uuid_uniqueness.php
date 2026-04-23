<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('ap_cheque_clearances')) {
            return;
        }

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

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('ap_cheque_clearances')) {
            return;
        }

        $hasClientUuidIndex = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ap_cheque_clearances'
               AND INDEX_NAME = 'uq_apc_client_uuid_active'"
        );

        if ((int) ($hasClientUuidIndex->c ?? 0) > 0) {
            DB::statement('ALTER TABLE ap_cheque_clearances DROP INDEX uq_apc_client_uuid_active');
        }

        $hasActiveClientUuid = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ap_cheque_clearances'
               AND COLUMN_NAME = 'active_client_uuid'"
        );

        if ((int) ($hasActiveClientUuid->c ?? 0) > 0) {
            DB::statement('ALTER TABLE ap_cheque_clearances DROP COLUMN active_client_uuid');
        }
    }
};


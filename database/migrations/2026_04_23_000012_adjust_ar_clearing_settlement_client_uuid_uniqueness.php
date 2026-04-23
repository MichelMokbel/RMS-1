<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('ar_clearing_settlements')) {
            return;
        }

        $legacyUuidUnique = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ar_clearing_settlements'
               AND INDEX_NAME = 'ar_clearing_settlements_client_uuid_unique'"
        );

        if ((int) ($legacyUuidUnique->c ?? 0) > 0) {
            DB::statement('ALTER TABLE ar_clearing_settlements DROP INDEX ar_clearing_settlements_client_uuid_unique');
        }

        $hasActiveClientUuid = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ar_clearing_settlements'
               AND COLUMN_NAME = 'active_client_uuid'"
        );

        if ((int) ($hasActiveClientUuid->c ?? 0) === 0) {
            DB::statement('ALTER TABLE ar_clearing_settlements ADD COLUMN active_client_uuid CHAR(36) AS (IF(voided_at IS NULL, client_uuid, NULL)) STORED');
        }

        $hasClientUuidIndex = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ar_clearing_settlements'
               AND INDEX_NAME = 'uq_ars_client_uuid_active'"
        );

        if ((int) ($hasClientUuidIndex->c ?? 0) === 0) {
            DB::statement('ALTER TABLE ar_clearing_settlements ADD UNIQUE INDEX uq_ars_client_uuid_active (active_client_uuid)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('ar_clearing_settlements')) {
            return;
        }

        $hasClientUuidIndex = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ar_clearing_settlements'
               AND INDEX_NAME = 'uq_ars_client_uuid_active'"
        );

        if ((int) ($hasClientUuidIndex->c ?? 0) > 0) {
            DB::statement('ALTER TABLE ar_clearing_settlements DROP INDEX uq_ars_client_uuid_active');
        }

        $hasActiveClientUuid = DB::selectOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'ar_clearing_settlements'
               AND COLUMN_NAME = 'active_client_uuid'"
        );

        if ((int) ($hasActiveClientUuid->c ?? 0) > 0) {
            DB::statement('ALTER TABLE ar_clearing_settlements DROP COLUMN active_client_uuid');
        }
    }
};


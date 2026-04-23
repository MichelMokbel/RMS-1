<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add a duplicate-prevention unique index on ap_payment_allocations for active (non-voided) rows.
 *
 * MySQL/MariaDB does not support partial unique indexes (WHERE clause), so we use a generated
 * sentinel column: TINYINT AS (IF(voided_at IS NULL, 1, NULL)) STORED.
 *
 * The sentinel is 1 for active rows (voided_at IS NULL) and NULL for voided rows. Because
 * NULL values are excluded from unique indexes in MySQL, voided allocations do not participate
 * in the uniqueness check, allowing a re-allocation to the same invoice from the same payment
 * after a void.
 *
 * The resulting unique index enforces:
 *   (payment_id, invoice_id) must be unique among non-voided rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ap_payment_allocations')) {
            return;
        }

        if ($this->indexExists('ap_payment_allocations', 'uniq_payment_invoice')) {
            DB::statement('ALTER TABLE ap_payment_allocations DROP INDEX uniq_payment_invoice');
        }

        // Add generated sentinel column if missing.
        if (! Schema::hasColumn('ap_payment_allocations', 'alloc_active_sentinel')) {
            DB::statement(
                'ALTER TABLE ap_payment_allocations '
                .'ADD COLUMN alloc_active_sentinel TINYINT AS (IF(voided_at IS NULL, 1, NULL)) STORED'
            );
        }

        // Add unique index if missing.
        if (! $this->indexExists('ap_payment_allocations', 'ap_payment_allocations_active_unique')) {
            if ($this->hasDuplicates()) {
                throw new RuntimeException(
                    'Cannot add ap_payment_allocations_active_unique: duplicate active '
                    .'(payment_id, invoice_id) rows exist. '
                    .'Void or remove duplicate allocations before running this migration.'
                );
            }

            DB::statement(
                'ALTER TABLE ap_payment_allocations '
                .'ADD UNIQUE INDEX ap_payment_allocations_active_unique '
                .'(payment_id, invoice_id, alloc_active_sentinel)'
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ap_payment_allocations')) {
            return;
        }

        if ($this->indexExists('ap_payment_allocations', 'ap_payment_allocations_active_unique')) {
            DB::statement('ALTER TABLE ap_payment_allocations DROP INDEX ap_payment_allocations_active_unique');
        }

        if (Schema::hasColumn('ap_payment_allocations', 'alloc_active_sentinel')) {
            Schema::table('ap_payment_allocations', function (Blueprint $table) {
                $table->dropColumn('alloc_active_sentinel');
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
            'SELECT 1 FROM information_schema.statistics '
            .'WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $index]
        );

        return $row !== null;
    }

    /**
     * Detect rows where the same payment_id+invoice_id has more than one non-voided allocation.
     */
    private function hasDuplicates(): bool
    {
        return DB::table('ap_payment_allocations')
            ->select(['payment_id', 'invoice_id'])
            ->whereNull('voided_at')
            ->groupBy('payment_id', 'invoice_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }
};

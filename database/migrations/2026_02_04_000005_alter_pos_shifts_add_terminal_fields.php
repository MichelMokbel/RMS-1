<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_shifts')) {
            return;
        }

        Schema::table('pos_shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_shifts', 'terminal_id')) {
                $table->unsignedBigInteger('terminal_id')->nullable()->after('branch_id');
                $table->index(['terminal_id', 'status'], 'pos_shifts_terminal_status_index');
            }
            if (! Schema::hasColumn('pos_shifts', 'device_id')) {
                $table->string('device_id', 80)->nullable()->after('terminal_id');
            }
        });

        // Add FK in a second step (allows the column to exist even if pos_terminals isn't present).
        if (Schema::hasTable('pos_terminals') && Schema::hasColumn('pos_shifts', 'terminal_id')) {
            Schema::table('pos_shifts', function (Blueprint $table) {
                // Best-effort: only add if not already present (Laravel doesn't expose FK existence).
                $table->foreign('terminal_id', 'pos_shifts_terminal_fk')
                    ->references('id')
                    ->on('pos_terminals')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_shifts')) {
            return;
        }

        Schema::table('pos_shifts', function (Blueprint $table) {
            if (Schema::hasColumn('pos_shifts', 'terminal_id')) {
                $table->dropForeign('pos_shifts_terminal_fk');
                $table->dropIndex('pos_shifts_terminal_status_index');
                $table->dropColumn('terminal_id');
            }
            if (Schema::hasColumn('pos_shifts', 'device_id')) {
                $table->dropColumn('device_id');
            }
        });
    }
};


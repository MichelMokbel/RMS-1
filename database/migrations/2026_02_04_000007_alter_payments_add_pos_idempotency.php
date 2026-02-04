<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'client_uuid')) {
                $table->char('client_uuid', 36)->nullable()->after('customer_id');
                $table->unique(['client_uuid'], 'payments_client_uuid_unique');
            }
            if (! Schema::hasColumn('payments', 'terminal_id')) {
                $table->unsignedBigInteger('terminal_id')->nullable()->after('client_uuid');
                $table->index(['terminal_id', 'received_at'], 'payments_terminal_received_at_index');
            }
            if (! Schema::hasColumn('payments', 'pos_shift_id')) {
                $table->unsignedBigInteger('pos_shift_id')->nullable()->after('terminal_id');
            }
        });

        if (Schema::hasTable('pos_terminals') && Schema::hasColumn('payments', 'terminal_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreign('terminal_id', 'payments_terminal_fk')
                    ->references('id')
                    ->on('pos_terminals')
                    ->nullOnDelete();
            });
        }
        if (Schema::hasTable('pos_shifts') && Schema::hasColumn('payments', 'pos_shift_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreign('pos_shift_id', 'payments_shift_fk')
                    ->references('id')
                    ->on('pos_shifts')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'client_uuid')) {
                $table->dropUnique('payments_client_uuid_unique');
                $table->dropColumn('client_uuid');
            }
            if (Schema::hasColumn('payments', 'terminal_id')) {
                $table->dropForeign('payments_terminal_fk');
                $table->dropIndex('payments_terminal_received_at_index');
                $table->dropColumn('terminal_id');
            }
            if (Schema::hasColumn('payments', 'pos_shift_id')) {
                $table->dropForeign('payments_shift_fk');
                $table->dropColumn('pos_shift_id');
            }
        });
    }
};


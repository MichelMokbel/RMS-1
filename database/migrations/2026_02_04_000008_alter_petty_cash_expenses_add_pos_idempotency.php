<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('petty_cash_expenses')) {
            return;
        }

        Schema::table('petty_cash_expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('petty_cash_expenses', 'client_uuid')) {
                $table->char('client_uuid', 36)->nullable()->after('id');
                $table->unique(['client_uuid'], 'petty_cash_expenses_client_uuid_unique');
            }
            if (! Schema::hasColumn('petty_cash_expenses', 'terminal_id')) {
                $table->unsignedBigInteger('terminal_id')->nullable()->after('client_uuid');
            }
            if (! Schema::hasColumn('petty_cash_expenses', 'pos_shift_id')) {
                $table->unsignedBigInteger('pos_shift_id')->nullable()->after('terminal_id');
                $table->index(['pos_shift_id'], 'petty_cash_expenses_pos_shift_id_index');
            }
        });

        if (Schema::hasTable('pos_terminals') && Schema::hasColumn('petty_cash_expenses', 'terminal_id')) {
            Schema::table('petty_cash_expenses', function (Blueprint $table) {
                $table->foreign('terminal_id', 'petty_cash_expenses_terminal_fk')
                    ->references('id')
                    ->on('pos_terminals')
                    ->nullOnDelete();
            });
        }
        if (Schema::hasTable('pos_shifts') && Schema::hasColumn('petty_cash_expenses', 'pos_shift_id')) {
            Schema::table('petty_cash_expenses', function (Blueprint $table) {
                $table->foreign('pos_shift_id', 'petty_cash_expenses_shift_fk')
                    ->references('id')
                    ->on('pos_shifts')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('petty_cash_expenses')) {
            return;
        }

        Schema::table('petty_cash_expenses', function (Blueprint $table) {
            if (Schema::hasColumn('petty_cash_expenses', 'client_uuid')) {
                $table->dropUnique('petty_cash_expenses_client_uuid_unique');
                $table->dropColumn('client_uuid');
            }
            if (Schema::hasColumn('petty_cash_expenses', 'terminal_id')) {
                $table->dropForeign('petty_cash_expenses_terminal_fk');
                $table->dropColumn('terminal_id');
            }
            if (Schema::hasColumn('petty_cash_expenses', 'pos_shift_id')) {
                $table->dropForeign('petty_cash_expenses_shift_fk');
                $table->dropIndex('petty_cash_expenses_pos_shift_id_index');
                $table->dropColumn('pos_shift_id');
            }
        });
    }
};


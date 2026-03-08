<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_terminals') || Schema::hasColumn('pos_terminals', 'print_agent_seen_at')) {
            return;
        }

        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->timestamp('print_agent_seen_at')->nullable()->after('last_seen_at');
            $table->index('print_agent_seen_at', 'pos_terminals_print_agent_seen_at_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_terminals') || ! Schema::hasColumn('pos_terminals', 'print_agent_seen_at')) {
            return;
        }

        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->dropIndex('pos_terminals_print_agent_seen_at_index');
            $table->dropColumn('print_agent_seen_at');
        });
    }
};

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
            if (! Schema::hasColumn('pos_shifts', 'closing_card_cents')) {
                $table->bigInteger('closing_card_cents')->default(0)->after('closing_cash_cents');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_shifts')) {
            return;
        }

        Schema::table('pos_shifts', function (Blueprint $table) {
            if (Schema::hasColumn('pos_shifts', 'closing_card_cents')) {
                $table->dropColumn('closing_card_cents');
            }
        });
    }
};

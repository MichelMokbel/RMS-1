<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'ar_clearing_settlements_settlement_method_index';

    public function up(): void
    {
        if (! Schema::hasTable('ar_clearing_settlements')
            || ! Schema::hasColumn('ar_clearing_settlements', 'settlement_method')
            || Schema::hasIndex('ar_clearing_settlements', self::INDEX)) {
            return;
        }

        Schema::table('ar_clearing_settlements', function (Blueprint $table): void {
            $table->index('settlement_method', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ar_clearing_settlements')
            || ! Schema::hasIndex('ar_clearing_settlements', self::INDEX)) {
            return;
        }

        Schema::table('ar_clearing_settlements', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};

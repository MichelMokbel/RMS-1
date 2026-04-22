<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_spend_snapshots')) {
            return;
        }

        Schema::table('marketing_spend_snapshots', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketing_spend_snapshots', 'ad_id')) {
                $table->foreignId('ad_id')
                    ->nullable()
                    ->after('ad_set_id')
                    ->constrained('marketing_ads')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('marketing_spend_snapshots', 'reach')) {
                $table->bigInteger('reach')->default(0)->after('impressions');
            }
        });

        Schema::table('marketing_spend_snapshots', function (Blueprint $table): void {
            $table->index(['ad_id', 'snapshot_date'], 'marketing_spend_ad_date_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketing_spend_snapshots')) {
            return;
        }

        Schema::table('marketing_spend_snapshots', function (Blueprint $table): void {
            $table->dropIndex('marketing_spend_ad_date_index');
        });

        Schema::table('marketing_spend_snapshots', function (Blueprint $table): void {
            if (Schema::hasColumn('marketing_spend_snapshots', 'ad_id')) {
                $table->dropConstrainedForeignId('ad_id');
            }

            if (Schema::hasColumn('marketing_spend_snapshots', 'reach')) {
                $table->dropColumn('reach');
            }
        });
    }
};

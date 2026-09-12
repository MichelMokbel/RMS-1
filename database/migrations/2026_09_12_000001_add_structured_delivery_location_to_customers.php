<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('portal_delivery_latitude', 9, 6)->nullable()->after('portal_delivery_address');
            $table->decimal('portal_delivery_longitude', 10, 6)->nullable()->after('portal_delivery_latitude');
            $table->string('portal_delivery_place_id', 255)->nullable()->after('portal_delivery_longitude');
            $table->string('portal_delivery_building', 200)->nullable()->after('portal_delivery_place_id');
            $table->string('portal_delivery_unit', 100)->nullable()->after('portal_delivery_building');
            $table->string('portal_delivery_instructions', 1000)->nullable()->after('portal_delivery_unit');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->decimal('delivery_latitude', 9, 6)->nullable()->after('delivery_address');
            $table->decimal('delivery_longitude', 10, 6)->nullable()->after('delivery_latitude');
            $table->string('delivery_place_id', 255)->nullable()->after('delivery_longitude');
            $table->string('delivery_building', 200)->nullable()->after('delivery_place_id');
            $table->string('delivery_unit', 100)->nullable()->after('delivery_building');
            $table->string('delivery_instructions', 1000)->nullable()->after('delivery_unit');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'portal_delivery_latitude',
                'portal_delivery_longitude',
                'portal_delivery_place_id',
                'portal_delivery_building',
                'portal_delivery_unit',
                'portal_delivery_instructions',
            ]);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'delivery_latitude',
                'delivery_longitude',
                'delivery_place_id',
                'delivery_building',
                'delivery_unit',
                'delivery_instructions',
            ]);
        });
    }
};

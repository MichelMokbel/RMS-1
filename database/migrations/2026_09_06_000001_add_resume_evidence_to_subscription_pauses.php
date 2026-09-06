<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_subscription_pauses', function (Blueprint $table): void {
            $table->dateTime('resumed_at', 6)->nullable()->after('reason');
            $table->integer('resumed_by')->nullable()->after('resumed_at');
            $table->foreign('resumed_by', 'meal_sub_pauses_resumed_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meal_subscription_pauses', function (Blueprint $table): void {
            $table->dropForeign('meal_sub_pauses_resumed_by_fk');
            $table->dropColumn(['resumed_at', 'resumed_by']);
        });
    }
};

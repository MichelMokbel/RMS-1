<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meal_subscription_pauses')) {
            Schema::create('meal_subscription_pauses', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('subscription_id');
                $table->date('pause_start');
                $table->date('pause_end');
                $table->string('reason', 255)->nullable();
                // align with legacy users.id (int) and avoid FK issues
                $table->integer('created_by')->nullable();
                $table->timestamp('created_at')->nullable()->useCurrent();

                $table->index('subscription_id', 'meal_subscription_pauses_sub_id_idx');
            });
        }

        // Deliberately not adding foreign keys here to avoid legacy FK errors.
    }

    public function down(): void
    {
        // Non-destructive
    }
};


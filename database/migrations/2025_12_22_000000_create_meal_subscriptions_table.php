<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meal_subscriptions')) {
            Schema::create('meal_subscriptions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('subscription_code', 50)->unique();
                $table->integer('customer_id');
                $table->integer('branch_id');
                $table->enum('status', ['active','paused','cancelled','expired'])->default('active');
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->enum('default_order_type', ['Delivery','Takeaway'])->default('Delivery');
                $table->time('delivery_time')->nullable();
                $table->text('address_snapshot')->nullable();
                $table->string('phone_snapshot', 50)->nullable();
                $table->enum('preferred_role', ['main','diet','vegetarian'])->default('main');
                $table->boolean('include_salad')->default(true);
                $table->boolean('include_dessert')->default(true);
                $table->text('notes')->nullable();
                // align with legacy users.id (int(11))
                $table->integer('created_by')->nullable();
                $table->timestamp('created_at')->nullable()->useCurrent();
                $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            });
        }

        // Deliberately not adding foreign keys here to avoid duplicate / legacy FK issues.
    }

    public function down(): void
    {
        // Non-destructive
    }
};


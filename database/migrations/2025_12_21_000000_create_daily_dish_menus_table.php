<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_dish_menus')) {
            Schema::create('daily_dish_menus', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('branch_id');
                $table->date('service_date');
                $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
                $table->text('notes')->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamp('created_at')->nullable()->useCurrent();
                $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();

                $table->unique(['branch_id', 'service_date'], 'daily_dish_menus_branch_date_unique');
            });
        }

        // Deliberately skipping foreign keys to avoid legacy FK errors; branch/created_by kept as plain ints.
    }

    public function down(): void
    {
        // Non-destructive: do not drop legacy tables.
    }
};


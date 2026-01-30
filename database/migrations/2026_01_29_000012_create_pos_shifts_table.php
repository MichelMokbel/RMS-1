<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_shifts')) {
            return;
        }

        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            // When a shift is closed we set active = NULL (allows multiple closed shifts).
            // Only one active shift per user per branch (active=1).
            $table->boolean('active')->nullable()->default(true);
            $table->string('status', 20)->default('open'); // open|closed|void

            // Money amounts are integers in minor units ("cents").
            $table->bigInteger('opening_cash_cents')->default(0);
            $table->bigInteger('closing_cash_cents')->nullable();
            $table->bigInteger('expected_cash_cents')->nullable();
            $table->bigInteger('variance_cents')->nullable();

            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();

            $table->timestamps();

            $table->unique(['branch_id', 'user_id', 'active'], 'pos_shifts_one_active_per_user_branch');
            $table->index(['branch_id', 'status'], 'pos_shifts_branch_status_index');
            $table->index(['user_id', 'status'], 'pos_shifts_user_status_index');
            $table->index(['opened_at'], 'pos_shifts_opened_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_shifts');
    }
};


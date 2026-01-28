<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_transfers')) {
            return;
        }

        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->integer('from_branch_id');
            $table->integer('to_branch_id');
            $table->date('transfer_date');
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['from_branch_id'], 'inventory_transfers_from_branch_index');
            $table->index(['to_branch_id'], 'inventory_transfers_to_branch_index');
            $table->index(['status'], 'inventory_transfers_status_index');
            $table->index(['transfer_date'], 'inventory_transfers_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedInteger('branch_id');
                $table->unsignedBigInteger('customer_id')->nullable();

                $table->string('source', 20); // pos|ar
                $table->string('method', 20); // cash|card|online|bank|voucher

                $table->bigInteger('amount_cents');
                $table->string('currency', 3)->default('KWD');

                $table->timestamp('received_at')->nullable();
                $table->string('reference', 120)->nullable();
                $table->text('notes')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();

                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('void_reason', 255)->nullable();

                $table->timestamps();

                $table->index(['branch_id', 'source', 'received_at'], 'payments_branch_source_received_at_index');
                $table->index(['customer_id', 'received_at'], 'payments_customer_received_at_index');
            });
        }

        if (! Schema::hasTable('payment_allocations')) {
            Schema::create('payment_allocations', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedBigInteger('payment_id');
                $table->string('allocatable_type', 150);
                $table->unsignedBigInteger('allocatable_id');
                $table->bigInteger('amount_cents');

                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('void_reason', 255)->nullable();

                $table->timestamps();

                $table->index(['payment_id'], 'payment_allocations_payment_id_index');
                $table->index(['allocatable_type', 'allocatable_id'], 'payment_allocations_allocatable_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ar_clearing_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('bank_account_id');
            $table->enum('settlement_method', ['card', 'cheque'])->index();
            $table->date('settlement_date');
            $table->integer('amount_cents')->default(0);
            $table->char('client_uuid', 36)->nullable()->unique();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'settlement_date']);
            $table->index(['settlement_method', 'voided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ar_clearing_settlements');
    }
};

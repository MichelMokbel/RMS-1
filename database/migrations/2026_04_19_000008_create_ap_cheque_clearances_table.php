<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ap_cheque_clearances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('bank_account_id');
            $table->unsignedBigInteger('ap_payment_id');
            $table->date('clearance_date');
            $table->decimal('amount', 15, 2);
            $table->char('client_uuid', 36)->nullable()->unique();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'clearance_date']);
            $table->index('ap_payment_id');
            $table->foreign('ap_payment_id')->references('id')->on('ap_payments');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ap_cheque_clearances ADD COLUMN active_sentinel TINYINT AS (IF(voided_at IS NULL, 1, NULL)) STORED');
            DB::statement('ALTER TABLE ap_cheque_clearances ADD UNIQUE INDEX uq_apc_payment_active (ap_payment_id, active_sentinel)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_cheque_clearances');
    }
};

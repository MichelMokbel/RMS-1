<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_checkout_attempts', function (Blueprint $table): void {
            $table->json('operations_tracking')->nullable()->after('notification_dispatch');
            $table->dateTime('operations_next_action_at', 6)->nullable()->after('next_recovery_at');
            $table->index(
                ['operations_next_action_at', 'id'],
                'payment_attempts_operations_due_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payment_checkout_attempts', function (Blueprint $table): void {
            $table->dropIndex('payment_attempts_operations_due_idx');
            $table->dropColumn(['operations_tracking', 'operations_next_action_at']);
        });
    }
};

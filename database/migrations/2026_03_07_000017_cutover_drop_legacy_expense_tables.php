<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('expense_attachments');
        Schema::dropIfExists('expense_payments');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('petty_cash_expenses');

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Hard cutover migration; legacy tables are intentionally not recreated.
    }
};

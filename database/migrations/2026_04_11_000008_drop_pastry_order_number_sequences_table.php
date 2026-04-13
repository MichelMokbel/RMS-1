<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pastry_order_number_sequences');
    }

    public function down(): void
    {
        // Intentionally not recreated — DocumentSequenceService (type 'pastry_order') replaces this table.
    }
};

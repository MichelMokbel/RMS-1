<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_number_sequences')) {
            return;
        }

        Schema::create('order_number_sequences', function (Blueprint $table) {
            $table->string('year', 4)->primary();
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequences');
    }
};


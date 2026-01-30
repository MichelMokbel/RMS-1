<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_terms')) {
            Schema::create('payment_terms', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 100);
                $table->unsignedInteger('days')->default(0);
                $table->boolean('is_credit')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['is_credit', 'is_active'], 'payment_terms_credit_active_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_terms');
    }
};

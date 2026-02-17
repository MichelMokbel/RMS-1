<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_food_list_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_list_id')->constrained('company_food_employee_lists')->cascadeOnDelete();
            $table->string('category', 50);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['employee_list_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_food_list_categories');
    }
};

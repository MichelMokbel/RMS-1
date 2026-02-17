<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_food_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('company_food_projects')->cascadeOnDelete();
            $table->string('employee_name');
            $table->string('email');
            $table->uuid('edit_token')->unique();
            $table->foreignId('salad_option_id')->nullable()->constrained('company_food_options')->nullOnDelete();
            $table->foreignId('appetizer_option_id_1')->nullable()->constrained('company_food_options')->nullOnDelete();
            $table->foreignId('appetizer_option_id_2')->nullable()->constrained('company_food_options')->nullOnDelete();
            $table->foreignId('main_option_id')->nullable()->constrained('company_food_options')->nullOnDelete();
            $table->foreignId('sweet_option_id')->nullable()->constrained('company_food_options')->nullOnDelete();
            $table->foreignId('location_option_id')->nullable()->constrained('company_food_options')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'employee_name']);
            $table->index('edit_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_food_orders');
    }
};

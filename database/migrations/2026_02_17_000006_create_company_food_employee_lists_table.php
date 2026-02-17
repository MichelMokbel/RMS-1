<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_food_employee_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('company_food_projects')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_food_employee_lists');
    }
};

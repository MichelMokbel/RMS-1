<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_sequences')) {
            return;
        }

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('branch_id');
            $table->string('type', 50); // e.g. ar_invoice, sale
            $table->string('year', 4);
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['branch_id', 'type', 'year'], 'document_sequences_branch_type_year_unique');
            $table->index(['type', 'year'], 'document_sequences_type_year_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};


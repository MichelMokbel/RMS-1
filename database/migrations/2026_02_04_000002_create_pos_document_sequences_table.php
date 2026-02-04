<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_document_sequences')) {
            return;
        }

        Schema::create('pos_document_sequences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('terminal_id');
            $table->date('business_date');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['terminal_id', 'business_date'], 'pos_doc_seq_terminal_date_unique');
            $table->index(['business_date'], 'pos_doc_seq_business_date_index');

            $table->foreign('terminal_id', 'pos_doc_seq_terminal_fk')
                ->references('id')
                ->on('pos_terminals')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_document_sequences');
    }
};


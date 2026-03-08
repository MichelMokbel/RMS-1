<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_print_stream_events')) {
            return;
        }

        Schema::create('pos_print_stream_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('terminal_id');
            $table->string('event_type', 40);
            $table->json('payload_json');
            $table->timestamp('created_at')->nullable();

            $table->index(['terminal_id', 'id'], 'pos_print_stream_events_terminal_id_index');
            $table->index('created_at', 'pos_print_stream_events_created_at_index');

            $table->foreign('terminal_id', 'pos_print_stream_events_terminal_fk')
                ->references('id')
                ->on('pos_terminals')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_print_stream_events');
    }
};

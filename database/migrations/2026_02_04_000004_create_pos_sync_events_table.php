<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_sync_events')) {
            return;
        }

        Schema::create('pos_sync_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('terminal_id');

            $table->string('event_id', 100);
            $table->char('client_uuid', 36);
            $table->string('type', 60);

            $table->string('server_entity_type', 80)->nullable();
            $table->unsignedBigInteger('server_entity_id')->nullable();

            $table->string('status', 20)->default('applied'); // processing|applied|failed|rejected
            $table->timestamp('applied_at')->nullable();

            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->unique(['client_uuid'], 'pos_sync_events_client_uuid_unique');
            $table->unique(['terminal_id', 'event_id'], 'pos_sync_events_terminal_event_unique');
            $table->index(['terminal_id', 'type', 'applied_at'], 'pos_sync_events_terminal_type_applied_index');

            $table->foreign('terminal_id', 'pos_sync_events_terminal_fk')
                ->references('id')
                ->on('pos_terminals')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sync_events');
    }
};

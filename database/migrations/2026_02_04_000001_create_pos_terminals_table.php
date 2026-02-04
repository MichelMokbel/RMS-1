<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_terminals')) {
            return;
        }

        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->bigIncrements('id');

            // NOTE: branch FK intentionally not enforced (branches.id is INT from dump).
            $table->unsignedInteger('branch_id');

            $table->string('code', 20); // e.g. T01
            $table->string('name', 80);

            // Ties Windows machine to terminal.
            $table->string('device_id', 80)->nullable();

            $table->boolean('active')->default(true);
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique(['branch_id', 'code'], 'pos_terminals_branch_code_unique');
            $table->unique(['device_id'], 'pos_terminals_device_id_unique');
            $table->index(['branch_id', 'active'], 'pos_terminals_branch_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_terminals');
    }
};


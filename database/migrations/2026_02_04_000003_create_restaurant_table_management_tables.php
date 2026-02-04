<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('restaurant_areas')) {
            Schema::create('restaurant_areas', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('branch_id');
                $table->string('name', 80);
                $table->integer('display_order')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->index(['branch_id', 'active'], 'restaurant_areas_branch_active_index');
            });
        }

        if (! Schema::hasTable('restaurant_tables')) {
            Schema::create('restaurant_tables', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('branch_id');
                $table->unsignedBigInteger('area_id')->nullable();
                $table->string('code', 30);
                $table->string('name', 80);
                $table->unsignedInteger('capacity')->nullable();
                $table->integer('display_order')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->unique(['branch_id', 'code'], 'restaurant_tables_branch_code_unique');
                $table->index(['branch_id', 'active'], 'restaurant_tables_branch_active_index');
                $table->index(['area_id', 'display_order'], 'restaurant_tables_area_order_index');

                $table->foreign('area_id', 'restaurant_tables_area_fk')
                    ->references('id')
                    ->on('restaurant_areas')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasTable('restaurant_table_sessions')) {
            Schema::create('restaurant_table_sessions', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedInteger('branch_id');
                $table->unsignedBigInteger('table_id');

                $table->enum('status', ['open', 'closed'])->default('open');
                $table->boolean('active')->default(true);

                $table->unsignedBigInteger('opened_by')->nullable();
                $table->string('device_id', 80)->nullable();
                $table->unsignedBigInteger('terminal_id')->nullable();
                $table->unsignedBigInteger('pos_shift_id')->nullable();

                $table->dateTime('opened_at');
                $table->dateTime('closed_at')->nullable();
                $table->unsignedInteger('guests')->nullable();
                $table->text('notes')->nullable();

                // MySQL can't do partial unique indexes; use generated column to enforce one active session per table.
                if (DB::connection()->getDriverName() === 'mysql') {
                    $table->unsignedBigInteger('active_table_id')
                        ->nullable()
                        ->storedAs('IF(`active` = 1, `table_id`, NULL)');
                    $table->unique(['active_table_id'], 'restaurant_table_sessions_one_active_per_table');
                }

                $table->timestamps();

                $table->index(['branch_id', 'status'], 'restaurant_table_sessions_branch_status_index');
                $table->index(['terminal_id', 'status'], 'restaurant_table_sessions_terminal_status_index');

                $table->foreign('table_id', 'restaurant_table_sessions_table_fk')
                    ->references('id')
                    ->on('restaurant_tables')
                    ->cascadeOnDelete();

                $table->foreign('terminal_id', 'restaurant_table_sessions_terminal_fk')
                    ->references('id')
                    ->on('pos_terminals')
                    ->nullOnDelete();

                $table->foreign('pos_shift_id', 'restaurant_table_sessions_shift_fk')
                    ->references('id')
                    ->on('pos_shifts')
                    ->nullOnDelete();
            });

            // Non-MySQL: best-effort constraint.
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("CREATE UNIQUE INDEX restaurant_table_sessions_one_active_per_table ON restaurant_table_sessions (table_id) WHERE active = true");
            } elseif (DB::connection()->getDriverName() !== 'mysql') {
                Schema::table('restaurant_table_sessions', function (Blueprint $table) {
                    $table->unique(['table_id', 'active'], 'restaurant_table_sessions_table_active_unique');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_table_sessions');
        Schema::dropIfExists('restaurant_tables');
        Schema::dropIfExists('restaurant_areas');
    }
};


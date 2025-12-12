<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->softDeletes();
            });

            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (! Schema::hasColumn('categories', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }

            if (! Schema::hasColumn('categories', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'parent_id')) {
                try {
                    $table->dropForeign(['parent_id']);
                } catch (Throwable $e) {
                    // Ignore if the foreign key does not exist or cannot be dropped
                }

                $table->foreign('parent_id')->references('id')->on('categories')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            if (Schema::hasColumn('categories', 'updated_at')) {
                $table->dropColumn('updated_at');
            }

            if (Schema::hasColumn('categories', 'parent_id')) {
                try {
                    $table->dropForeign(['parent_id']);
                } catch (Throwable $e) {
                    // ignore
                }

                $table->foreign('parent_id')->references('id')->on('categories');
            }
        });
    }
};

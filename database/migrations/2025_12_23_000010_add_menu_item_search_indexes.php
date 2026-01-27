<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        Schema::table('menu_items', function (Blueprint $table) {
            if (! Schema::hasIndex('menu_items', 'menu_items_code_index')) {
                $table->index('code', 'menu_items_code_index');
            }
            if (! Schema::hasIndex('menu_items', 'menu_items_name_index')) {
                $table->index('name', 'menu_items_name_index');
            }
            if (! Schema::hasIndex('menu_items', 'menu_items_arabic_name_index')) {
                $table->index('arabic_name', 'menu_items_arabic_name_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        Schema::table('menu_items', function (Blueprint $table) {
            if (Schema::hasIndex('menu_items', 'menu_items_code_index')) {
                $table->dropIndex('menu_items_code_index');
            }
            if (Schema::hasIndex('menu_items', 'menu_items_name_index')) {
                $table->dropIndex('menu_items_name_index');
            }
            if (Schema::hasIndex('menu_items', 'menu_items_arabic_name_index')) {
                $table->dropIndex('menu_items_arabic_name_index');
            }
        });
    }
};

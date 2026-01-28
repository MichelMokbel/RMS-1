
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

        if (Schema::hasColumn('menu_items', 'status')) {
            return;
        }

        Schema::table('menu_items', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('display_order');
            $table->index('status', 'menu_items_status_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        if (! Schema::hasColumn('menu_items', 'status')) {
            return;
        }

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropIndex('menu_items_status_index');
            $table->dropColumn('status');
        });
    }
};


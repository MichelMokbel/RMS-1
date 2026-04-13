<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only insert if no "Pastry Items" category exists yet
        $exists = DB::table('categories')->where('name', 'Pastry Items')->whereNull('deleted_at')->exists();

        if (! $exists) {
            DB::table('categories')->insert([
                'name'       => 'Pastry Items',
                'parent_id'  => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('categories')->where('name', 'Pastry Items')->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('finance_settings')) {
            return;
        }

        Schema::create('finance_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('lock_date')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
        });

        // Seed singleton row (id=1) from env config if present.
        $lockDate = config('finance.lock_date');
        DB::table('finance_settings')->insert([
            'id' => 1,
            'lock_date' => $lockDate ?: null,
            'updated_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_settings');
    }
};

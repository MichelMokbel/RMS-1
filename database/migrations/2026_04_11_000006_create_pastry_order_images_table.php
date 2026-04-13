<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pastry_order_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pastry_order_id');
            $table->string('image_path');
            $table->string('image_disk')->default('s3');
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('pastry_order_id')->references('id')->on('pastry_orders')->cascadeOnDelete();
            $table->index('pastry_order_id');
        });

        Schema::table('pastry_orders', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'image_disk']);
        });
    }

    public function down(): void
    {
        Schema::table('pastry_orders', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('notes');
            $table->string('image_disk')->default('s3')->after('image_path');
        });

        Schema::dropIfExists('pastry_order_images');
    }
};

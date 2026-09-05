<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('smtp_host', 255);
            $table->unsignedSmallInteger('smtp_port');
            $table->string('security_mode', 20);
            $table->text('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->string('from_address', 254);
            $table->string('from_name', 255);
            $table->text('daily_dish_admin_emails');
            $table->unsignedBigInteger('revision')->default(1);
            $table->integer('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement('ALTER TABLE mail_settings ADD CONSTRAINT mail_settings_singleton_check CHECK (id = 1)');
        DB::statement("ALTER TABLE mail_settings ADD CONSTRAINT mail_settings_security_check CHECK (security_mode IN ('implicit_tls', 'starttls', 'none'))");
        DB::statement('ALTER TABLE mail_settings ADD CONSTRAINT mail_settings_revision_check CHECK (revision > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};

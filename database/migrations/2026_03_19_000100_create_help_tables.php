<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('help_articles')) {
            Schema::create('help_articles', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->string('module', 50)->index();
                $table->text('summary')->nullable();
                $table->longText('body_markdown')->nullable();
                $table->json('prerequisites')->nullable();
                $table->json('keywords')->nullable();
                $table->string('target_route')->nullable();
                $table->json('target_route_params')->nullable();
                $table->string('locale', 10)->default('en')->index();
                $table->string('status', 20)->default('draft')->index();
                $table->string('visibility_mode', 20)->default('all');
                $table->json('allowed_roles')->nullable();
                $table->json('allowed_permissions')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->json('meta')->nullable();
                $table->integer('created_by')->nullable()->index();
                $table->integer('updated_by')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('help_article_steps')) {
            Schema::create('help_article_steps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('article_id')->constrained('help_articles')->cascadeOnDelete();
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('title');
                $table->longText('body_markdown')->nullable();
                $table->string('image_key')->nullable()->index();
                $table->string('cta_label')->nullable();
                $table->string('cta_route')->nullable();
                $table->json('cta_route_params')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('help_article_faqs')) {
            Schema::create('help_article_faqs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('article_id')->nullable()->constrained('help_articles')->cascadeOnDelete();
                $table->string('module', 50)->nullable()->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('question');
                $table->longText('answer_markdown');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('help_article_assets')) {
            Schema::create('help_article_assets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('article_id')->nullable()->constrained('help_articles')->cascadeOnDelete();
                $table->string('key')->unique();
                $table->string('disk')->default('public');
                $table->string('path')->nullable();
                $table->string('alt_text')->nullable();
                $table->string('viewport', 20)->default('desktop');
                $table->string('checksum')->nullable();
                $table->timestamp('captured_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('help_chat_sessions')) {
            Schema::create('help_chat_sessions', function (Blueprint $table) {
                $table->id();
                $table->integer('user_id')->index();
                $table->string('locale', 10)->default('en');
                $table->string('title')->nullable();
                $table->text('last_question')->nullable();
                $table->timestamp('last_answered_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('help_chat_messages')) {
            Schema::create('help_chat_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('session_id')->constrained('help_chat_sessions')->cascadeOnDelete();
                $table->string('role', 20);
                $table->longText('content');
                $table->json('citations')->nullable();
                $table->string('confidence', 20)->nullable();
                $table->boolean('fallback')->default(false);
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('help_chat_messages');
        Schema::dropIfExists('help_chat_sessions');
        Schema::dropIfExists('help_article_assets');
        Schema::dropIfExists('help_article_faqs');
        Schema::dropIfExists('help_article_steps');
        Schema::dropIfExists('help_articles');
    }
};

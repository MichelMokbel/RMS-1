<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers', 'merged_into_customer_id')) {
                $table->integer('merged_into_customer_id')->nullable()->after('id');
                $table->foreign('merged_into_customer_id', 'customers_merged_into_customer_id_foreign')
                    ->references('id')
                    ->on('customers')
                    ->restrictOnDelete();
            }
        });

        if (! Schema::hasTable('customer_match_reviews')) {
            Schema::create('customer_match_reviews', function (Blueprint $table): void {
                $table->id();
                $table->integer('user_id');
                $table->integer('customer_id');
                $table->integer('candidate_customer_id');
                $table->json('reason_codes');
                $table->string('profile_fingerprint', 64);
                $table->string('status', 20)->default('pending');
                $table->json('ai_suggestion')->nullable();
                $table->timestamp('ai_checked_at')->nullable();
                $table->text('decision_note')->nullable();
                $table->integer('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->unsignedBigInteger('merge_audit_id')->nullable();
                $table->timestamps();

                $table->foreign('user_id', 'customer_match_reviews_user_id_foreign')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
                $table->foreign('customer_id', 'customer_match_reviews_customer_id_foreign')
                    ->references('id')
                    ->on('customers')
                    ->restrictOnDelete();
                $table->foreign('candidate_customer_id', 'customer_match_reviews_candidate_customer_id_foreign')
                    ->references('id')
                    ->on('customers')
                    ->restrictOnDelete();
                $table->foreign('reviewed_by', 'customer_match_reviews_reviewed_by_foreign')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
                $table->foreign('merge_audit_id', 'customer_match_reviews_merge_audit_id_foreign')
                    ->references('id')
                    ->on('accounting_audit_logs')
                    ->restrictOnDelete();

                $table->unique(
                    ['user_id', 'customer_id', 'candidate_customer_id'],
                    'customer_match_reviews_subject_candidate_unique'
                );
                $table->index(['status', 'created_at'], 'customer_match_reviews_status_created_at_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_match_reviews');

        Schema::table('customers', function (Blueprint $table): void {
            if (Schema::hasColumn('customers', 'merged_into_customer_id')) {
                $table->dropForeign('customers_merged_into_customer_id_foreign');
                $table->dropColumn('merged_into_customer_id');
            }
        });
    }
};

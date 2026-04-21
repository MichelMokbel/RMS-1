<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createMarketingSettings();
        $this->createMarketingPlatformAccounts();
        $this->createMarketingCampaigns();
        $this->createMarketingAdSets();
        $this->createMarketingAds();
        $this->createMarketingSpendSnapshots();
        $this->createMarketingAssets();
        $this->createMarketingAssetVersions();
        $this->createMarketingAssetUsages();
        $this->createMarketingBriefs();
        $this->createMarketingComments();
        $this->createMarketingApprovals();
        $this->createMarketingSyncLogs();
        $this->createMarketingUtms();
        $this->createMarketingActivityLogs();
    }

    public function down(): void
    {
        $tables = [
            'marketing_activity_logs',
            'marketing_utms',
            'marketing_sync_logs',
            'marketing_approvals',
            'marketing_comments',
            'marketing_briefs',
            'marketing_asset_usages',
            'marketing_asset_versions',
            'marketing_assets',
            'marketing_spend_snapshots',
            'marketing_ads',
            'marketing_ad_sets',
            'marketing_campaigns',
            'marketing_platform_accounts',
            'marketing_settings',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createMarketingSettings(): void
    {
        if (Schema::hasTable('marketing_settings')) {
            return;
        }

        Schema::create('marketing_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('meta_app_id')->nullable();
            $table->text('meta_app_secret')->nullable();
            $table->text('meta_system_user_token')->nullable();
            $table->string('meta_business_id')->nullable();
            $table->text('google_developer_token')->nullable();
            $table->string('google_login_customer_id')->nullable();
            $table->string('google_client_id')->nullable();
            $table->text('google_client_secret')->nullable();
            $table->text('google_refresh_token')->nullable();
            $table->string('s3_asset_bucket')->nullable();
            $table->boolean('meta_sync_enabled')->default(false);
            $table->boolean('google_sync_enabled')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    private function createMarketingPlatformAccounts(): void
    {
        if (Schema::hasTable('marketing_platform_accounts')) {
            return;
        }

        Schema::create('marketing_platform_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('platform'); // meta | google
            $table->string('external_account_id');
            $table->string('account_name');
            $table->string('currency', 3)->nullable();
            $table->string('timezone')->nullable();
            $table->string('status')->default('active'); // active | paused | disconnected
            $table->timestamp('last_synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'external_account_id'], 'mpa_platform_account_unique');
            $table->index(['platform', 'status'], 'mpa_platform_status_index');
        });
    }

    private function createMarketingCampaigns(): void
    {
        if (Schema::hasTable('marketing_campaigns')) {
            return;
        }

        Schema::create('marketing_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_account_id')
                ->constrained('marketing_platform_accounts')
                ->cascadeOnDelete();
            $table->string('external_campaign_id');
            $table->string('name');
            $table->string('status');
            $table->string('objective')->nullable();
            $table->bigInteger('daily_budget_micro')->nullable();
            $table->bigInteger('lifetime_budget_micro')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->json('platform_data')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();

            $table->unique(['platform_account_id', 'external_campaign_id'], 'mc_account_campaign_unique');
            $table->index(['platform_account_id', 'status'], 'mc_account_status_index');
        });
    }

    private function createMarketingAdSets(): void
    {
        if (Schema::hasTable('marketing_ad_sets')) {
            return;
        }

        Schema::create('marketing_ad_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')
                ->constrained('marketing_campaigns')
                ->cascadeOnDelete();
            $table->string('external_adset_id');
            $table->string('name');
            $table->string('status');
            $table->bigInteger('daily_budget_micro')->nullable();
            $table->json('platform_data')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'external_adset_id'], 'mas_campaign_adset_unique');
            $table->index(['campaign_id', 'status'], 'mas_campaign_status_index');
        });
    }

    private function createMarketingAds(): void
    {
        if (Schema::hasTable('marketing_ads')) {
            return;
        }

        Schema::create('marketing_ads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_set_id')
                ->constrained('marketing_ad_sets')
                ->cascadeOnDelete();
            $table->string('external_ad_id');
            $table->string('name');
            $table->string('status');
            $table->string('creative_type')->nullable();
            $table->json('platform_data')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['ad_set_id', 'external_ad_id'], 'ma_adset_ad_unique');
            $table->index(['ad_set_id', 'status'], 'ma_adset_status_index');
        });
    }

    private function createMarketingSpendSnapshots(): void
    {
        if (Schema::hasTable('marketing_spend_snapshots')) {
            return;
        }

        Schema::create('marketing_spend_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_account_id')
                ->constrained('marketing_platform_accounts')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('ad_set_id')->nullable();
            $table->date('snapshot_date');
            $table->bigInteger('impressions')->default(0);
            $table->bigInteger('clicks')->default(0);
            $table->bigInteger('spend_micro')->default(0);
            $table->integer('conversions')->default(0);
            $table->json('platform_data')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id', 'mss_campaign_fk')
                ->references('id')->on('marketing_campaigns')
                ->nullOnDelete();
            $table->foreign('ad_set_id', 'mss_adset_fk')
                ->references('id')->on('marketing_ad_sets')
                ->nullOnDelete();

            $table->index(['platform_account_id', 'snapshot_date'], 'mss_account_date_index');
            $table->index(['campaign_id', 'snapshot_date'], 'mss_campaign_date_index');
            $table->index(['ad_set_id', 'snapshot_date'], 'mss_adset_date_index');
        });
    }

    private function createMarketingAssets(): void
    {
        if (Schema::hasTable('marketing_assets')) {
            return;
        }

        Schema::create('marketing_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type'); // image | video | copy | document
            $table->string('s3_key');
            $table->string('s3_bucket');
            $table->string('mime_type')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->string('status')->default('pending_review'); // pending_review | approved | rejected | archived
            $table->integer('current_version')->default(1);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['type', 'status'], 'mast_type_status_index');
            $table->index('uploaded_by', 'mast_uploaded_by_index');
        });
    }

    private function createMarketingAssetVersions(): void
    {
        if (Schema::hasTable('marketing_asset_versions')) {
            return;
        }

        Schema::create('marketing_asset_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')
                ->constrained('marketing_assets')
                ->cascadeOnDelete();
            $table->integer('version_number');
            $table->string('s3_key');
            $table->bigInteger('file_size')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'version_number'], 'mav_asset_version_unique');
        });
    }

    private function createMarketingAssetUsages(): void
    {
        if (Schema::hasTable('marketing_asset_usages')) {
            return;
        }

        Schema::create('marketing_asset_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')
                ->constrained('marketing_assets')
                ->cascadeOnDelete();
            $table->string('usageable_type');
            $table->unsignedBigInteger('usageable_id');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['usageable_type', 'usageable_id'], 'mau_usageable_index');
            $table->index('asset_id', 'mau_asset_index');
        });
    }

    private function createMarketingBriefs(): void
    {
        if (Schema::hasTable('marketing_briefs')) {
            return;
        }

        Schema::create('marketing_briefs', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->string('status')->default('draft'); // draft | pending_review | approved | rejected
            $table->date('due_date')->nullable();
            $table->text('objectives')->nullable();
            $table->text('target_audience')->nullable();
            $table->text('budget_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id', 'mb_campaign_fk')
                ->references('id')->on('marketing_campaigns')
                ->nullOnDelete();

            $table->index('status', 'mb_status_index');
            $table->index('campaign_id', 'mb_campaign_index');
            $table->index('created_by', 'mb_created_by_index');
        });
    }

    private function createMarketingComments(): void
    {
        if (Schema::hasTable('marketing_comments')) {
            return;
        }

        Schema::create('marketing_comments', function (Blueprint $table): void {
            $table->id();
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->text('body');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['commentable_type', 'commentable_id'], 'mc_commentable_index');
        });
    }

    private function createMarketingApprovals(): void
    {
        if (Schema::hasTable('marketing_approvals')) {
            return;
        }

        Schema::create('marketing_approvals', function (Blueprint $table): void {
            $table->id();
            $table->string('approvable_type');
            $table->unsignedBigInteger('approvable_id');
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id'], 'map_approvable_index');
            $table->index('status', 'map_status_index');
            $table->index('reviewer_id', 'map_reviewer_index');
        });
    }

    private function createMarketingSyncLogs(): void
    {
        if (Schema::hasTable('marketing_sync_logs')) {
            return;
        }

        Schema::create('marketing_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_account_id')
                ->constrained('marketing_platform_accounts')
                ->cascadeOnDelete();
            $table->string('sync_type'); // campaigns | ad_sets | ads | spend
            $table->string('status')->default('pending'); // pending | running | completed | failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('records_synced')->default(0);
            $table->text('error_message')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['platform_account_id', 'sync_type', 'status'], 'msl_account_type_status_index');
            $table->index('created_at', 'msl_created_at_index');
        });
    }

    private function createMarketingUtms(): void
    {
        if (Schema::hasTable('marketing_utms')) {
            return;
        }

        Schema::create('marketing_utms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')
                ->constrained('marketing_campaigns')
                ->cascadeOnDelete();
            $table->string('utm_source');
            $table->string('utm_medium');
            $table->string('utm_campaign');
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('landing_page_url');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('campaign_id', 'mu_campaign_index');
        });
    }

    private function createMarketingActivityLogs(): void
    {
        if (Schema::hasTable('marketing_activity_logs')) {
            return;
        }

        Schema::create('marketing_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('actor_id', 'mal_actor_index');
            $table->index(['subject_type', 'subject_id'], 'mal_subject_index');
            $table->index('created_at', 'mal_created_at_index');
        });
    }
};

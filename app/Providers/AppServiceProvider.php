<?php

namespace App\Providers;

use App\Services\Ai\AiProviderInterface;
use App\Services\Ai\GeminiProvider;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use App\Services\Finance\FinanceSettingsService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiProviderInterface::class, GeminiProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::addNamespace('layouts', resource_path('views/components/layouts'));

        // Allow finance.lock_date to be managed in-app via DB (falls back to env).
        try {
            if (Schema::hasTable('finance_settings')) {
                $lockDate = app(FinanceSettingsService::class)->getLockDate();
                if ($lockDate !== null) {
                    Config::set('finance.lock_date', $lockDate);
                }
            }
        } catch (\Throwable $e) {
            // Don't block app boot if DB is unavailable during install/migrate.
        }
    }
}

<?php

namespace App\Providers;

use App\Contracts\PhoneVerificationProvider;
use App\Services\Ai\AiProviderInterface;
use App\Services\Ai\GeminiProvider;
use App\Services\Customers\AwsSnsPhoneVerificationProvider;
use InvalidArgumentException;
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
        $this->app->bind(PhoneVerificationProvider::class, function ($app) {
            return match ((string) config('services.customer_sms.provider', 'aws_sns')) {
                'aws_sns' => $app->make(AwsSnsPhoneVerificationProvider::class),
                default => throw new InvalidArgumentException('Unsupported customer SMS provider configured.'),
            };
        });
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

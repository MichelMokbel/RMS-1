<?php

namespace App\Providers;

use App\Contracts\PhoneVerificationProvider;
use App\Services\Ai\AiProviderInterface;
use App\Services\Ai\GeminiProvider;
use App\Services\Customers\AwsSnsPhoneVerificationProvider;
use App\Services\Customers\TelnyxPhoneVerificationProvider;
use App\Services\Finance\FinanceSettingsService;
use App\Services\Mail\MailSettingsService;
use App\Services\Payments\FakeSkipCashProvider;
use App\Services\Payments\HttpSkipCashProvider;
use App\Services\Payments\SkipCashProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MailSettingsService::class);
        $this->app->bind(AiProviderInterface::class, GeminiProvider::class);
        $this->app->singleton(SkipCashProvider::class, function ($app) {
            return (string) config('payments.skipcash.driver', 'http') === 'fake'
                ? $app->make(FakeSkipCashProvider::class)
                : $app->make(HttpSkipCashProvider::class);
        });
        $this->app->bind(PhoneVerificationProvider::class, function ($app) {
            return match ((string) config('services.customer_sms.provider', 'aws_sns')) {
                'aws_sns' => $app->make(AwsSnsPhoneVerificationProvider::class),
                'telnyx' => $app->make(TelnyxPhoneVerificationProvider::class),
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

        RateLimiter::for('public-membership-read', fn (Request $request): Limit => Limit::perMinute(300)
            ->by('public-membership-read|'.$request->ip()));
        RateLimiter::for('public-storefront-read', fn (Request $request): Limit => Limit::perMinute(600)
            ->by('public-storefront-read|'.$request->ip()));
        RateLimiter::for('public-storefront-events', fn (Request $request): Limit => Limit::perMinute(1200)
            ->by('public-storefront-events|'.$request->ip()));
        RateLimiter::for('public-daily-dish-read', fn (Request $request): Limit => Limit::perMinute(600)
            ->by('public-daily-dish-read|'.$request->ip()));
        RateLimiter::for('public-daily-dish-order', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('public-daily-dish-order|'.($request->user()?->id ?? $request->ip())));

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

        // SyncSubscriptionMealsOnInvoiceIssued is auto-discovered by Laravel — do not register manually.
    }
}

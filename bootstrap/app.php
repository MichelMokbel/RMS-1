<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use App\Http\Middleware\CheckActiveUser;
use App\Http\Middleware\EnsureAdmin;
use App\Console\Commands\UsersHashPasswords;
use App\Console\Commands\GenerateSubscriptionOrders;
use App\Services\Orders\SubscriptionOrderGenerationService;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        UsersHashPasswords::class,
        GenerateSubscriptionOrders::class,
    ])
    ->withSchedule(function (Schedule $schedule) {
        $time = config('subscriptions.generation_time', '06:00');
        $schedule->call(function () {
            $service = app(SubscriptionOrderGenerationService::class);
            $branches = \DB::table('meal_subscriptions')->distinct()->pluck('branch_id');
            $date = now()->toDateString();
            foreach ($branches as $branchId) {
                $service->generateForDate($date, (int) $branchId, 1, false);
            }
        })->dailyAt($time);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => CheckActiveUser::class,
            'ensure.admin' => EnsureAdmin::class,
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

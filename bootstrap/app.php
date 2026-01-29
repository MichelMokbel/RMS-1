<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use App\Http\Middleware\CheckActiveUser;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureActiveBranch;
use App\Console\Commands\UsersHashPasswords;
use App\Console\Commands\GenerateSubscriptionOrders;
use App\Console\Commands\RestoreDatabaseFromDump;
use App\Console\Commands\ImportDailyDishMenuFromForm;
use App\Console\Commands\IntegrityAudit;
use App\Console\Commands\ReapplySafeForeignKeys;
use App\Console\Commands\FinanceLockDate;
use App\Console\Commands\BackfillMenuItemBranches;
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
        RestoreDatabaseFromDump::class,
        ImportDailyDishMenuFromForm::class,
        IntegrityAudit::class,
        ReapplySafeForeignKeys::class,
        FinanceLockDate::class,
        BackfillMenuItemBranches::class,
    ])
    ->withSchedule(function (Schedule $schedule) {
        $time = config('subscriptions.generation_time', '06:00');
        $schedule->call(function () {
            $service = app(SubscriptionOrderGenerationService::class);
            $actorId = (int) config('app.system_user_id');
            if (! $actorId) {
                logger()->warning('SYSTEM_USER_ID missing; subscription order generation skipped.');
                return;
            }
            $branches = DB::table('meal_subscriptions')->distinct()->pluck('branch_id');
            $date = now()->toDateString();
            foreach ($branches as $branchId) {
                $service->generateForDate($date, (int) $branchId, $actorId, false);
            }
        })->dailyAt($time);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => CheckActiveUser::class,
            'ensure.admin' => EnsureAdmin::class,
            'ensure.active-branch' => EnsureActiveBranch::class,
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

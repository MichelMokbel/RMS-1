<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IntegrityAudit extends Command
{
    protected $signature = 'data:integrity-audit {--details : Include up to 5 sample orphan ids per check}';

    protected $description = 'Audit core tables for orphaned foreign keys before enforcing constraints';

    public function handle(): int
    {
        $checks = $this->checks();
        $hasDetails = (bool) $this->option('details');

        $rows = [];
        foreach ($checks as $check) {
            if (! $this->canCheck($check)) {
                $rows[] = [$check['label'], 'SKIP', 'missing table/column'];
                continue;
            }

            $count = DB::table($check['table'].' as t')
                ->leftJoin($check['refTable'].' as r', 't.'.$check['column'], '=', 'r.'.$check['refColumn'])
                ->whereNotNull('t.'.$check['column'])
                ->whereNull('r.'.$check['refColumn'])
                ->count();

            $status = $count === 0 ? 'OK' : 'ORPHANS';
            $rows[] = [$check['label'], $status, (string) $count];

            if ($hasDetails && $count > 0) {
                $sample = DB::table($check['table'].' as t')
                    ->leftJoin($check['refTable'].' as r', 't.'.$check['column'], '=', 'r.'.$check['refColumn'])
                    ->whereNotNull('t.'.$check['column'])
                    ->whereNull('r.'.$check['refColumn'])
                    ->limit(5)
                    ->pluck('t.id')
                    ->all();

                $this->line($check['label'].' sample ids: '.implode(', ', $sample));
            }
        }

        $this->table(['Check', 'Status', 'Count'], $rows);

        $hasOrphans = collect($rows)->contains(fn ($row) => $row[1] === 'ORPHANS');

        return $hasOrphans ? Command::FAILURE : Command::SUCCESS;
    }

    private function checks(): array
    {
        $checks = [
            ['label' => 'orders.customer_id -> customers.id', 'table' => 'orders', 'column' => 'customer_id', 'refTable' => 'customers', 'refColumn' => 'id'],
            ['label' => 'orders.created_by -> users.id', 'table' => 'orders', 'column' => 'created_by', 'refTable' => 'users', 'refColumn' => 'id'],
            ['label' => 'order_items.order_id -> orders.id', 'table' => 'order_items', 'column' => 'order_id', 'refTable' => 'orders', 'refColumn' => 'id'],
            ['label' => 'order_items.menu_item_id -> menu_items.id', 'table' => 'order_items', 'column' => 'menu_item_id', 'refTable' => 'menu_items', 'refColumn' => 'id'],
            ['label' => 'ops_events.order_id -> orders.id', 'table' => 'ops_events', 'column' => 'order_id', 'refTable' => 'orders', 'refColumn' => 'id'],
            ['label' => 'ops_events.order_item_id -> order_items.id', 'table' => 'ops_events', 'column' => 'order_item_id', 'refTable' => 'order_items', 'refColumn' => 'id'],
            ['label' => 'ops_events.actor_user_id -> users.id', 'table' => 'ops_events', 'column' => 'actor_user_id', 'refTable' => 'users', 'refColumn' => 'id'],
            ['label' => 'daily_dish_menus.created_by -> users.id', 'table' => 'daily_dish_menus', 'column' => 'created_by', 'refTable' => 'users', 'refColumn' => 'id'],
            ['label' => 'subscription_order_runs.created_by -> users.id', 'table' => 'subscription_order_runs', 'column' => 'created_by', 'refTable' => 'users', 'refColumn' => 'id'],
            ['label' => 'subscription_order_run_errors.run_id -> subscription_order_runs.id', 'table' => 'subscription_order_run_errors', 'column' => 'run_id', 'refTable' => 'subscription_order_runs', 'refColumn' => 'id'],
            ['label' => 'subscription_order_run_errors.subscription_id -> meal_subscriptions.id', 'table' => 'subscription_order_run_errors', 'column' => 'subscription_id', 'refTable' => 'meal_subscriptions', 'refColumn' => 'id'],
            ['label' => 'meal_plan_request_orders.order_id -> orders.id', 'table' => 'meal_plan_request_orders', 'column' => 'order_id', 'refTable' => 'orders', 'refColumn' => 'id'],
            ['label' => 'meal_plan_request_orders.meal_plan_request_id -> meal_plan_requests.id', 'table' => 'meal_plan_request_orders', 'column' => 'meal_plan_request_id', 'refTable' => 'meal_plan_requests', 'refColumn' => 'id'],
        ];

        if (Schema::hasTable('branches')) {
            $checks[] = ['label' => 'orders.branch_id -> branches.id', 'table' => 'orders', 'column' => 'branch_id', 'refTable' => 'branches', 'refColumn' => 'id'];
            $checks[] = ['label' => 'ops_events.branch_id -> branches.id', 'table' => 'ops_events', 'column' => 'branch_id', 'refTable' => 'branches', 'refColumn' => 'id'];
            $checks[] = ['label' => 'subscription_order_runs.branch_id -> branches.id', 'table' => 'subscription_order_runs', 'column' => 'branch_id', 'refTable' => 'branches', 'refColumn' => 'id'];
        }

        return $checks;
    }

    private function canCheck(array $check): bool
    {
        return Schema::hasTable($check['table'])
            && Schema::hasTable($check['refTable'])
            && Schema::hasColumn($check['table'], $check['column'])
            && Schema::hasColumn($check['refTable'], $check['refColumn']);
    }
}

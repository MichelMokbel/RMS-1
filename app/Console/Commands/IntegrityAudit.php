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

        $this->appendStockReconciliationRow($rows, $hasDetails);
        $this->appendMenuItemBranchSanityRow($rows, $hasDetails);
        $this->appendLedgerBalancingRows($rows, $hasDetails);

        $this->table(['Check', 'Status', 'Count'], $rows);

        $hasFailures = collect($rows)->contains(fn ($row) => in_array($row[1], ['ORPHANS', 'MISMATCH', 'MISSING', 'UNBALANCED'], true));

        return $hasFailures ? Command::FAILURE : Command::SUCCESS;
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
            $checks[] = ['label' => 'meal_subscriptions.branch_id -> branches.id', 'table' => 'meal_subscriptions', 'column' => 'branch_id', 'refTable' => 'branches', 'refColumn' => 'id'];
            $checks[] = ['label' => 'meal_subscription_orders.branch_id -> branches.id', 'table' => 'meal_subscription_orders', 'column' => 'branch_id', 'refTable' => 'branches', 'refColumn' => 'id'];
        }

        $checks[] = ['label' => 'meal_subscriptions.created_by -> users.id', 'table' => 'meal_subscriptions', 'column' => 'created_by', 'refTable' => 'users', 'refColumn' => 'id'];
        $checks[] = ['label' => 'meal_subscriptions.meal_plan_request_id -> meal_plan_requests.id', 'table' => 'meal_subscriptions', 'column' => 'meal_plan_request_id', 'refTable' => 'meal_plan_requests', 'refColumn' => 'id'];
        $checks[] = ['label' => 'meal_subscription_pauses.created_by -> users.id', 'table' => 'meal_subscription_pauses', 'column' => 'created_by', 'refTable' => 'users', 'refColumn' => 'id'];

        return $checks;
    }

    private function canCheck(array $check): bool
    {
        return Schema::hasTable($check['table'])
            && Schema::hasTable($check['refTable'])
            && Schema::hasColumn($check['table'], $check['column'])
            && Schema::hasColumn($check['refTable'], $check['refColumn']);
    }

    private function appendStockReconciliationRow(array &$rows, bool $hasDetails): void
    {
        if (! Schema::hasTable('inventory_items') || ! Schema::hasTable('inventory_stocks') || ! Schema::hasColumn('inventory_items', 'current_stock')) {
            $rows[] = ['inventory_items.current_stock vs inventory_stocks sum', 'SKIP', 'missing column'];
            return;
        }

        $sub = DB::table('inventory_stocks')
            ->select('inventory_item_id', DB::raw('SUM(current_stock) as total_stock'))
            ->groupBy('inventory_item_id');

        $count = DB::table('inventory_items as i')
            ->leftJoinSub($sub, 's', fn ($join) => $join->on('i.id', '=', 's.inventory_item_id'))
            ->whereRaw('ABS(COALESCE(i.current_stock, 0) - COALESCE(s.total_stock, 0)) > 0.0005')
            ->count();

        $rows[] = ['inventory_items.current_stock vs inventory_stocks sum', $count === 0 ? 'OK' : 'MISMATCH', (string) $count];

        if ($hasDetails && $count > 0) {
            $sample = DB::table('inventory_items as i')
                ->leftJoinSub($sub, 's', fn ($join) => $join->on('i.id', '=', 's.inventory_item_id'))
                ->select('i.id')
                ->whereRaw('ABS(COALESCE(i.current_stock, 0) - COALESCE(s.total_stock, 0)) > 0.0005')
                ->limit(5)
                ->pluck('i.id')
                ->all();

            $this->line('inventory_items.current_stock vs inventory_stocks sum sample ids: '.implode(', ', $sample));
        }
    }

    private function appendMenuItemBranchSanityRow(array &$rows, bool $hasDetails): void
    {
        if (! Schema::hasTable('menu_items') || ! Schema::hasTable('menu_item_branches')) {
            $rows[] = ['active menu_items assigned to a branch', 'SKIP', 'missing table'];
            return;
        }

        $count = DB::table('menu_items as mi')
            ->where('mi.is_active', 1)
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('menu_item_branches as mib')
                    ->whereColumn('mib.menu_item_id', 'mi.id');
            })
            ->count();

        $rows[] = ['active menu_items assigned to a branch', $count === 0 ? 'OK' : 'MISSING', (string) $count];

        if ($hasDetails && $count > 0) {
            $sample = DB::table('menu_items as mi')
                ->select('mi.id')
                ->where('mi.is_active', 1)
                ->whereNotExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('menu_item_branches as mib')
                        ->whereColumn('mib.menu_item_id', 'mi.id');
                })
                ->limit(5)
                ->pluck('mi.id')
                ->all();

            $this->line('active menu_items missing branch assignment sample ids: '.implode(', ', $sample));
        }
    }

    private function appendLedgerBalancingRows(array &$rows, bool $hasDetails): void
    {
        if (! Schema::hasTable('subledger_entries') || ! Schema::hasTable('subledger_lines')) {
            $rows[] = ['subledger_entries balanced (posted)', 'SKIP', 'missing table'];
        } else {
            // Use a subquery selecting only grouped keys to remain compatible with MySQL ONLY_FULL_GROUP_BY.
            $q = DB::table('subledger_lines as l')
                ->join('subledger_entries as e', 'e.id', '=', 'l.entry_id')
                ->where('e.status', 'posted')
                ->select('l.entry_id')
                ->groupBy('l.entry_id')
                ->havingRaw('ABS(SUM(l.debit) - SUM(l.credit)) > 0.0001');

            $count = DB::query()->fromSub($q, 't')->count();
            $rows[] = ['subledger_entries balanced (posted)', $count === 0 ? 'OK' : 'UNBALANCED', (string) $count];

            if ($hasDetails && $count > 0) {
                $sample = DB::query()->fromSub($q->limit(5), 't')->pluck('entry_id')->all();

                $this->line('unbalanced subledger entry ids: '.implode(', ', $sample));
            }
        }

        if (! Schema::hasTable('gl_batches') || ! Schema::hasTable('gl_batch_lines')) {
            $rows[] = ['gl_batches balanced (posted)', 'SKIP', 'missing table'];
            return;
        }

        $q2 = DB::table('gl_batch_lines as l')
            ->join('gl_batches as b', 'b.id', '=', 'l.batch_id')
            ->where('b.status', 'posted')
            ->select('l.batch_id')
            ->groupBy('l.batch_id')
            ->havingRaw('ABS(SUM(l.debit_total) - SUM(l.credit_total)) > 0.0001');

        $count2 = DB::query()->fromSub($q2, 't')->count();
        $rows[] = ['gl_batches balanced (posted)', $count2 === 0 ? 'OK' : 'UNBALANCED', (string) $count2];

        if ($hasDetails && $count2 > 0) {
            $sample = DB::query()->fromSub($q2->limit(5), 't')->pluck('batch_id')->all();

            $this->line('unbalanced gl batch ids: '.implode(', ', $sample));
        }
    }
}

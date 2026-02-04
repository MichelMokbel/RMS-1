<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClearTransactionalTables extends Command
{
    protected $signature = 'db:clear-transactional
        {--force : Required. Acknowledge that this will delete all data in payables, receivables, payments, orders, sales, subscriptions, ledgers}';

    protected $description = 'Clear payables, receivables, payments, orders, sales, subscriptions, and ledgers (and their child tables).';

    /** @var list<string> Tables to truncate in dependency order (children first). */
    private array $tables = [
        'payment_allocations',
        'ap_payment_allocations',
        'ar_invoice_items',
        'ap_invoice_items',
        'sale_items',
        'order_items',
        'meal_subscription_orders',
        'subscription_order_run_errors',
        'subscription_order_runs',
        'meal_subscription_pauses',
        'meal_subscription_days',
        'payments',
        'ar_invoices',
        'ap_invoices',
        'ap_payments',
        'sales',
        'orders',
        'meal_subscriptions',
        'gl_batch_lines',
        'gl_batches',
        'subledger_lines',
        'subledger_entries',
    ];

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Refusing to run without --force. This command will delete all data in payables, receivables, payments, orders, sales, subscriptions, and ledgers.');
            return self::FAILURE;
        }

        $this->warn('About to clear: payables, receivables, payments, orders, sales, subscriptions, ledgers (gl_batches, gl_batch_lines, subledger_entries, subledger_lines) and their child tables.');

        if (! $this->confirm('Are you sure?', false)) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($this->tables as $table) {
                if (! Schema::hasTable($table)) {
                    $this->line("  Skip (missing): {$table}");
                    continue;
                }
                $count = DB::table($table)->count();
                DB::table($table)->truncate();
                $this->info("  Cleared {$table} ({$count} rows).");
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}

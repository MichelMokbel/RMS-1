<?php

namespace App\Console\Commands;

use App\Services\Orders\SubscriptionOrderGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateSubscriptionOrders extends Command
{
    protected $signature = 'orders:generate-subscriptions {date?} {--branch=} {--dry-run}';

    protected $description = 'Generate orders for active subscriptions using the published daily dish menu';

    public function handle(SubscriptionOrderGenerationService $service): int
    {
        $date = $this->argument('date') ?? now()->toDateString();
        $branchOpt = $this->option('branch');
        $dry = (bool) $this->option('dry-run');

        $branches = $branchOpt
            ? collect([(int) $branchOpt])
            : DB::table('meal_subscriptions')->distinct()->pluck('branch_id');

        $summary = [
            'created' => 0,
            'skipped_existing' => 0,
            'skipped_no_menu' => 0,
            'skipped_no_items' => 0,
            'errors' => [],
        ];

        foreach ($branches as $branchId) {
            $res = $service->generateForDate($date, (int) $branchId, auth()->id() ?? 1, $dry);
            $summary['created'] += $res['created_count'];
            $summary['skipped_existing'] += $res['skipped_existing_count'];
            $summary['skipped_no_menu'] += $res['skipped_no_menu_count'];
            $summary['skipped_no_items'] += $res['skipped_no_items_count'];
            $summary['errors'] = array_merge($summary['errors'], $res['errors']);
        }

        $this->info("Date: {$date}");
        $this->info('Created: '.$summary['created']);
        $this->info('Skipped existing: '.$summary['skipped_existing']);
        $this->info('Skipped no menu: '.$summary['skipped_no_menu']);
        $this->info('Skipped no items: '.$summary['skipped_no_items']);

        if (! empty($summary['errors'])) {
            $this->warn('Errors:');
            foreach ($summary['errors'] as $err) {
                $this->line('- '.$err);
            }
        }

        return Command::SUCCESS;
    }
}


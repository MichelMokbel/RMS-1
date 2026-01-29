<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillMenuItemBranches extends Command
{
    protected $signature = 'menu:backfill-item-branches {--dry-run : Do not write, only report} {--branch= : Force a branch_id target}';

    protected $description = 'Ensure every active menu item is assigned to at least one branch';

    public function handle(): int
    {
        if (! Schema::hasTable('menu_items') || ! Schema::hasTable('menu_item_branches')) {
            $this->warn('Missing tables: menu_items or menu_item_branches.');
            return Command::SUCCESS;
        }

        $targetBranchId = (int) ($this->option('branch') ?: config('inventory.default_branch_id', 1));
        if ($targetBranchId <= 0) {
            $targetBranchId = 1;
        }

        if (Schema::hasTable('branches')) {
            $q = DB::table('branches')->where('id', $targetBranchId);
            if (Schema::hasColumn('branches', 'is_active')) {
                $q->where('is_active', 1);
            }
            if (! $q->exists()) {
                $fallback = DB::table('branches')
                    ->when(Schema::hasColumn('branches', 'is_active'), fn ($qq) => $qq->where('is_active', 1))
                    ->orderBy('id')
                    ->value('id');
                if (! $fallback) {
                    $this->warn('No active branches found; cannot backfill menu_item_branches.');
                    return Command::FAILURE;
                }
                $targetBranchId = (int) $fallback;
            }
        }

        $dry = (bool) $this->option('dry-run');

        $missing = DB::table('menu_items as mi')
            ->where('mi.is_active', 1)
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('menu_item_branches as mib')
                    ->whereColumn('mib.menu_item_id', 'mi.id');
            })
            ->pluck('mi.id')
            ->all();

        $count = count($missing);
        if ($count === 0) {
            $this->info('All active menu items already have branch assignments.');
            return Command::SUCCESS;
        }

        $this->line("Active menu items missing branch assignment: {$count}");

        if ($dry) {
            $sample = array_slice($missing, 0, 10);
            $this->line('Sample ids: '.implode(', ', $sample));
            return Command::SUCCESS;
        }

        $now = now();
        $rows = array_map(fn ($id) => [
            'menu_item_id' => (int) $id,
            'branch_id' => $targetBranchId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $missing);

        DB::table('menu_item_branches')->insert($rows);

        $this->info("Backfilled {$count} items to branch_id={$targetBranchId}.");

        return Command::SUCCESS;
    }
}

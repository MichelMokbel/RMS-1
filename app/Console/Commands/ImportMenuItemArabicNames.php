<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportMenuItemArabicNames extends Command
{
    protected $signature = 'menu:import-arabic-names
                            {path? : CSV path (default: arabic-names.csv)}
                            {--dry-run : Validate and report without writing}';

    protected $description = 'Import arabic_name values into menu_items from CSV (id, arabic_name)';

    public function handle(): int
    {
        if (! Schema::hasTable('menu_items')) {
            $this->error('Table menu_items does not exist.');
            return Command::FAILURE;
        }

        if (! Schema::hasColumn('menu_items', 'arabic_name')) {
            $this->error('Column menu_items.arabic_name does not exist.');
            return Command::FAILURE;
        }

        $inputPath = (string) ($this->argument('path') ?? 'arabic-names.csv');
        if (! is_file($inputPath)) {
            $this->error("CSV file not found: {$inputPath}");
            return Command::FAILURE;
        }

        $fh = fopen($inputPath, 'r');
        if ($fh === false) {
            $this->error("Unable to open CSV file: {$inputPath}");
            return Command::FAILURE;
        }

        $header = fgetcsv($fh, 0, ',', '"', '\\');
        if ($header === false) {
            fclose($fh);
            $this->error('CSV is empty.');
            return Command::FAILURE;
        }

        $header = array_map(fn ($v) => strtolower(trim((string) $v)), $header);
        $idIdx = array_search('id', $header, true);
        $arabicIdx = array_search('arabic_name', $header, true);

        if ($idIdx === false || $arabicIdx === false) {
            fclose($fh);
            $this->error('CSV must include headers: id, arabic_name');
            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $notFound = 0;

        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if (! isset($row[$idIdx])) {
                $skipped++;
                continue;
            }

            $processed++;
            $id = (int) trim((string) $row[$idIdx]);
            $arabicName = trim((string) ($row[$arabicIdx] ?? ''));

            if ($id <= 0 || $arabicName === '') {
                $skipped++;
                continue;
            }

            $exists = DB::table('menu_items')->where('id', $id)->exists();
            if (! $exists) {
                $notFound++;
                continue;
            }

            if (! $dryRun) {
                DB::table('menu_items')
                    ->where('id', $id)
                    ->update([
                        'arabic_name' => $arabicName,
                        'updated_at' => now(),
                    ]);
            }

            $updated++;
        }

        fclose($fh);

        $this->info($dryRun ? 'Dry run complete.' : 'Import complete.');
        $this->line("Processed: {$processed}");
        $this->line("Updated: {$updated}");
        $this->line("Skipped (invalid/empty): {$skipped}");
        $this->line("Not found by id: {$notFound}");

        return Command::SUCCESS;
    }
}


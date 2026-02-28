<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExportMenuItemsMissingArabic extends Command
{
    protected $signature = 'menu:export-missing-arabic
                            {path? : Output CSV path (default: storage/app/menu_items_missing_arabic.csv)}
                            {--include-inactive : Include inactive menu items}';

    protected $description = 'Export menu items missing arabic_name to CSV (id, english_name)';

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

        $pathArg = (string) ($this->argument('path') ?? '');
        $defaultPath = storage_path('app/menu_items_missing_arabic.csv');
        $outputPath = $pathArg !== '' ? $pathArg : $defaultPath;

        $dir = dirname($outputPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            $this->error("Unable to create directory: {$dir}");
            return Command::FAILURE;
        }

        $query = DB::table('menu_items')
            ->select(['id', 'name'])
            ->whereRaw("TRIM(COALESCE(arabic_name, '')) = ''")
            ->orderBy('id');

        if (! $this->option('include-inactive') && Schema::hasColumn('menu_items', 'is_active')) {
            $query->where('is_active', 1);
        }

        $rows = $query->get();

        $fp = fopen($outputPath, 'w');
        if ($fp === false) {
            $this->error("Unable to write file: {$outputPath}");
            return Command::FAILURE;
        }

        fputcsv($fp, ['id', 'english_name']);
        foreach ($rows as $row) {
            fputcsv($fp, [(int) $row->id, (string) $row->name]);
        }
        fclose($fp);

        $this->info('Export complete.');
        $this->line('Rows: '.$rows->count());
        $this->line('File: '.$outputPath);

        return Command::SUCCESS;
    }
}


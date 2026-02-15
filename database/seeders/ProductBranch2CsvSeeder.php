<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductBranch2CsvSeeder extends Seeder
{
    /**
     * Seed menu items from docs/Products-branch-2.csv for branch 2.
     *
     * - Always inserts every row as a new menu item (even if name exists).
     * - Uses DB::table() to bypass the MenuItem booted() hook that auto-adds branch 1.
     * - Associates each new item with branch 2 only.
     */
    public function run(): void
    {
        $csvPath = base_path('docs/Products-branch-2.csv');

        if (! file_exists($csvPath)) {
            $this->command->error("CSV file not found at: {$csvPath}");

            return;
        }

        $raw = file_get_contents($csvPath);
        if ($raw === false) {
            $this->command->error("Unable to read CSV file: {$csvPath}");

            return;
        }
        $raw = str_replace(["\xC2\xA0", "\xA0"], ' ', $raw);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            $this->command->error('CSV file is empty or unreadable.');

            return;
        }

        $headers = array_map(function ($h) {
            return trim($h, "\xEF\xBB\xBF \t\n\r\0\x0B");
        }, $headers);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($headers)) {
                continue;
            }
            $rows[] = array_combine($headers, $row);
        }
        fclose($handle);

        $this->command->info(count($rows).' rows read from CSV.');

        // ---------------------------------------------------------------
        // 1. Build category name -> id map (firstOrCreate missing ones)
        // ---------------------------------------------------------------
        $categoryNames = array_unique(array_filter(array_column($rows, 'Product Category Name')));
        $categoryMap = [];

        foreach ($categoryNames as $catName) {
            $catName = trim($catName);
            if ($catName === '') {
                continue;
            }
            $category = Category::withTrashed()->firstOrCreate(
                ['name' => $catName],
                ['description' => null]
            );
            if ($category->trashed()) {
                $category->restore();
            }
            $categoryMap[$catName] = $category->id;
        }

        $this->command->info(count($categoryMap).' categories resolved.');

        // ---------------------------------------------------------------
        // 2. Determine starting counter for auto-generated menu codes
        // ---------------------------------------------------------------
        $maxMenuCode = MenuItem::query()
            ->where('code', 'like', 'MI-%')
            ->selectRaw("MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) as max_num")
            ->value('max_num');
        $nextMenuNum = ($maxMenuCode ?? 0) + 1;

        // ---------------------------------------------------------------
        // 3. Insert all rows — always create new items, branch 2 only
        // ---------------------------------------------------------------
        $inserted = 0;
        $skipped = 0;
        $now = now();

        DB::transaction(function () use (
            $rows,
            $categoryMap,
            &$nextMenuNum,
            &$inserted,
            &$skipped,
            $now,
        ) {
            foreach ($rows as $row) {
                $name = trim($row['Name*'] ?? '');
                if ($name === '') {
                    $skipped++;

                    continue;
                }

                $categoryName = trim($row['Product Category Name'] ?? '');
                $categoryId = $categoryMap[$categoryName] ?? null;
                $uom = trim($row['UOM*'] ?? '');
                $salePrice = (float) ($row['Sale Price'] ?? 0);
                $isActive = mb_strtolower(trim($row['Active'] ?? '')) === 'y'
                    || mb_strtolower(trim($row['Active'] ?? '')) === 'yes';

                $code = 'MI-'.str_pad((string) $nextMenuNum, 6, '0', STR_PAD_LEFT);
                $nextMenuNum++;

                $unit = $this->normaliseUnit($uom);

                $menuItemId = DB::table('menu_items')->insertGetId([
                    'code' => $code,
                    'name' => $name,
                    'arabic_name' => null,
                    'category_id' => $categoryId,
                    'selling_price_per_unit' => $salePrice,
                    'unit' => $unit,
                    'is_active' => $isActive,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('menu_item_branches')->insert([
                    'menu_item_id' => $menuItemId,
                    'branch_id' => 2,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $inserted++;
            }
        });

        $this->command->info("Menu items inserted: {$inserted}, skipped: {$skipped}");
        $this->command->info('Branch 2 product seeding complete.');
    }

    private function normaliseUnit(string $uom): string
    {
        $lower = mb_strtolower(trim($uom));

        return match ($lower) {
            'ea', 'each', 'pcs', 'piece' => MenuItem::UNIT_EACH,
            'dz', 'dozen' => MenuItem::UNIT_DOZEN,
            'kg', 'kgs' => MenuItem::UNIT_KG,
            default => MenuItem::UNIT_EACH,
        };
    }
}

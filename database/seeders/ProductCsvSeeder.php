<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductCsvSeeder extends Seeder
{
    /**
     * Seed inventory items and menu items from docs/Products.csv.
     *
     * - Category "Raw Materials" -> inventory_items (uses Cost Price)
     * - All other categories    -> menu_items      (uses Sale Price)
     * - Skips rows whose name already exists in the target table.
     * - Branch 1 association is handled automatically by model boot hooks.
     */
    public function run(): void
    {
        $csvPath = base_path('docs/Products.csv');

        if (! file_exists($csvPath)) {
            $this->command->error("CSV file not found at: {$csvPath}");

            return;
        }

        // Read the raw contents and fix encoding issues (replace non-breaking
        // spaces and other problematic bytes) before parsing as CSV.
        $raw = file_get_contents($csvPath);
        if ($raw === false) {
            $this->command->error("Unable to read CSV file: {$csvPath}");

            return;
        }
        // Replace UTF-8 non-breaking space (C2 A0) and raw 0xA0 with normal space
        $raw = str_replace(["\xC2\xA0", "\xA0"], ' ', $raw);

        // Open from a memory stream with cleaned content
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);

        // Read header row
        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            $this->command->error('CSV file is empty or unreadable.');

            return;
        }

        // Normalise headers (trim BOM and whitespace)
        $headers = array_map(function ($h) {
            return trim($h, "\xEF\xBB\xBF \t\n\r\0\x0B");
        }, $headers);

        // Parse all rows
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($headers)) {
                continue; // skip malformed rows
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
            // Restore if it was soft-deleted
            if ($category->trashed()) {
                $category->restore();
            }
            $categoryMap[$catName] = $category->id;
        }

        $this->command->info(count($categoryMap).' categories resolved.');

        // ---------------------------------------------------------------
        // 2. Determine starting counters for auto-generated codes
        // ---------------------------------------------------------------
        $maxItemCode = InventoryItem::query()
            ->where('item_code', 'like', 'ITEM-%')
            ->selectRaw("MAX(CAST(SUBSTRING(item_code, 6) AS UNSIGNED)) as max_num")
            ->value('max_num');
        $nextItemNum = ($maxItemCode ?? 0) + 1;

        $maxMenuCode = MenuItem::query()
            ->where('code', 'like', 'MI-%')
            ->selectRaw("MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) as max_num")
            ->value('max_num');
        $nextMenuNum = ($maxMenuCode ?? 0) + 1;

        // ---------------------------------------------------------------
        // 3. Collect existing names so we can skip duplicates efficiently
        // ---------------------------------------------------------------
        $existingInventoryNames = InventoryItem::pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->flip()->toArray();
        $existingMenuNames = MenuItem::pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->flip()->toArray();

        // ---------------------------------------------------------------
        // 4. Process rows inside a transaction
        // ---------------------------------------------------------------
        $inventoryInserted = 0;
        $inventorySkipped = 0;
        $menuInserted = 0;
        $menuSkipped = 0;

        DB::transaction(function () use (
            $rows,
            $categoryMap,
            &$nextItemNum,
            &$nextMenuNum,
            &$existingInventoryNames,
            &$existingMenuNames,
            &$inventoryInserted,
            &$inventorySkipped,
            &$menuInserted,
            &$menuSkipped,
        ) {
            foreach ($rows as $row) {
                $name = trim($row['Name*'] ?? '');
                if ($name === '') {
                    continue;
                }

                $categoryName = trim($row['Product Category Name'] ?? '');
                $categoryId = $categoryMap[$categoryName] ?? null;
                $uom = trim($row['UOM*'] ?? '');
                $salePrice = (float) ($row['Sale Price'] ?? 0);
                $costPrice = (float) ($row['Cost Price'] ?? 0);
                $arabicName = trim($row['Arabic Name'] ?? '');
                $isActive = mb_strtolower(trim($row['Active'] ?? '')) === 'yes';

                $nameLower = mb_strtolower($name);

                if ($categoryName === 'Raw Materials') {
                    // ----- Inventory Item -----
                    if (isset($existingInventoryNames[$nameLower])) {
                        $inventorySkipped++;

                        continue;
                    }

                    $itemCode = 'ITEM-'.str_pad((string) $nextItemNum, 3, '0', STR_PAD_LEFT);
                    $nextItemNum++;

                    InventoryItem::create([
                        'item_code' => $itemCode,
                        'name' => $name,
                        'category_id' => $categoryId,
                        'unit_of_measure' => $uom,
                        'cost_per_unit' => $costPrice,
                        'status' => $isActive ? 'active' : 'discontinued',
                    ]);

                    $existingInventoryNames[$nameLower] = true;
                    $inventoryInserted++;
                } else {
                    // ----- Menu Item -----
                    if (isset($existingMenuNames[$nameLower])) {
                        $menuSkipped++;

                        continue;
                    }

                    $code = 'MI-'.str_pad((string) $nextMenuNum, 6, '0', STR_PAD_LEFT);
                    $nextMenuNum++;

                    // Normalise unit to match MenuItem constants
                    $unit = $this->normaliseUnit($uom);

                    MenuItem::create([
                        'code' => $code,
                        'name' => $name,
                        'arabic_name' => $arabicName !== '' ? $arabicName : null,
                        'category_id' => $categoryId,
                        'selling_price_per_unit' => $salePrice,
                        'unit' => $unit,
                        'is_active' => $isActive,
                    ]);

                    $existingMenuNames[$nameLower] = true;
                    $menuInserted++;
                }
            }
        });

        $this->command->info("Inventory items — inserted: {$inventoryInserted}, skipped: {$inventorySkipped}");
        $this->command->info("Menu items      — inserted: {$menuInserted}, skipped: {$menuSkipped}");
        $this->command->info('Product CSV seeding complete.');
    }

    /**
     * Normalise a UOM string from the CSV to a MenuItem unit constant.
     */
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

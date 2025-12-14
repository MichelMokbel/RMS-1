<?php

namespace App\Console\Commands;

use App\Models\DailyDishMenu;
use App\Models\DailyDishMenuItem;
use App\Models\MenuItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class ImportDailyDishMenuFromForm extends Command
{
    protected $signature = 'daily-dish:import-month
        {--path=daily-dish.php : Path (relative to base_path) of the customer form file containing MENU_DAYS}
        {--branch=1 : branch_id to import into}
        {--month= : Month to import (YYYY-MM). Defaults to current month}
        {--status=published : daily_dish_menus.status to set for imported days}
        {--user-id=1 : created_by user id for created menus}
        {--dry-run : Parse and report only, do not write to DB}';

    protected $description = 'Import MENU_DAYS for a month from daily-dish.php into daily_dish_menus and daily_dish_menu_items; auto-create missing menu_items.';

    public function handle(): int
    {
        $branchId = (int) $this->option('branch');
        $month = (string) ($this->option('month') ?: now()->format('Y-m'));
        $status = (string) $this->option('status');
        $userId = (int) $this->option('user-id');
        $dryRun = (bool) $this->option('dry-run');

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('Invalid --month. Expected YYYY-MM.');
            return self::FAILURE;
        }

        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            $this->error('Invalid --status. Expected one of: draft, published, archived.');
            return self::FAILURE;
        }

        $pathOpt = (string) $this->option('path');
        $formPath = str_starts_with($pathOpt, DIRECTORY_SEPARATOR) ? $pathOpt : base_path($pathOpt);
        if (!is_file($formPath)) {
            throw new RuntimeException("Form file not found at: {$formPath}");
        }

        $contents = (string) file_get_contents($formPath);

        $translations = $this->parseTranslations($contents);
        $days = $this->parseMenuDays($contents);

        $monthDays = array_values(array_filter($days, fn (array $d) => str_starts_with((string) $d['key'], $month.'-')));
        if (count($monthDays) === 0) {
            $this->warn("No MENU_DAYS entries found for {$month}.");
            return self::SUCCESS;
        }

        $this->info("Parsed ".count($monthDays)." days for {$month} from {$pathOpt} (branch {$branchId}).");
        if ($dryRun) {
            $this->line('Dry run enabled: no database writes will occur.');
        }

        // Build a normalized lookup for existing menu_items to avoid O(n) queries.
        $existing = MenuItem::query()->select(['id', 'name', 'arabic_name'])->get();
        $byNorm = [];
        foreach ($existing as $mi) {
            $byNorm[$this->norm((string) $mi->name)] = $mi;
        }

        $createdMenuItems = 0;
        $updatedMenuItems = 0;
        $createdMenus = 0;
        $updatedMenus = 0;
        $createdMenuItemLinks = 0;

        $hasStatusCol = Schema::hasColumn('menu_items', 'status');

        $run = function () use (
            $monthDays,
            $translations,
            $branchId,
            $status,
            $userId,
            $dryRun,
            $hasStatusCol,
            &$byNorm,
            &$createdMenuItems,
            &$updatedMenuItems,
            &$createdMenus,
            &$updatedMenus,
            &$createdMenuItemLinks
        ) {
            foreach ($monthDays as $day) {
                $date = (string) $day['key'];

                $menu = DailyDishMenu::query()->where('branch_id', $branchId)->whereDate('service_date', $date)->first();
                if (!$menu) {
                    $createdMenus++;
                    if (!$dryRun) {
                        $menu = DailyDishMenu::create([
                            'branch_id' => $branchId,
                            'service_date' => $date,
                            'status' => $status,
                            'created_by' => $userId,
                        ]);
                    } else {
                        $menu = new DailyDishMenu(['branch_id' => $branchId, 'service_date' => $date, 'status' => $status, 'created_by' => $userId]);
                        $menu->id = -1;
                    }
                } else {
                    $updatedMenus++;
                    if (!$dryRun) {
                        $menu->status = $status;
                        $menu->save();
                    }
                }

                // Replace items
                if (!$dryRun && $menu->exists) {
                    $menu->items()->delete();
                }

                $sort = 0;
                foreach (($day['mains'] ?? []) as $mainName) {
                    $mi = $this->getOrCreateMenuItem(
                        (string) $mainName,
                        $translations,
                        $byNorm,
                        $hasStatusCol,
                        $dryRun,
                        $createdMenuItems,
                        $updatedMenuItems
                    );
                    $createdMenuItemLinks++;
                    if (!$dryRun && $menu->exists) {
                        DailyDishMenuItem::create([
                            'daily_dish_menu_id' => $menu->id,
                            'menu_item_id' => $mi->id,
                            'role' => 'main',
                            'sort_order' => $sort++,
                            'is_required' => false,
                        ]);
                    }
                }

                $saladName = (string) ($day['salad'] ?? '');
                if ($saladName !== '') {
                    $mi = $this->getOrCreateMenuItem(
                        $saladName,
                        $translations,
                        $byNorm,
                        $hasStatusCol,
                        $dryRun,
                        $createdMenuItems,
                        $updatedMenuItems
                    );
                    $createdMenuItemLinks++;
                    if (!$dryRun && $menu->exists) {
                        DailyDishMenuItem::create([
                            'daily_dish_menu_id' => $menu->id,
                            'menu_item_id' => $mi->id,
                            'role' => 'salad',
                            'sort_order' => 0,
                            'is_required' => false,
                        ]);
                    }
                }

                $dessertName = (string) ($day['dessert'] ?? '');
                if ($dessertName !== '') {
                    $mi = $this->getOrCreateMenuItem(
                        $dessertName,
                        $translations,
                        $byNorm,
                        $hasStatusCol,
                        $dryRun,
                        $createdMenuItems,
                        $updatedMenuItems
                    );
                    $createdMenuItemLinks++;
                    if (!$dryRun && $menu->exists) {
                        DailyDishMenuItem::create([
                            'daily_dish_menu_id' => $menu->id,
                            'menu_item_id' => $mi->id,
                            'role' => 'dessert',
                            'sort_order' => 0,
                            'is_required' => false,
                        ]);
                    }
                }
            }
        };

        if ($dryRun) {
            $run();
        } else {
            DB::transaction($run);
        }

        $this->newLine();
        $this->info('Import complete.');
        $this->line("Menus: created {$createdMenus}, touched(existing) {$updatedMenus}");
        $this->line("Menu items: created {$createdMenuItems}, updated(arabic/status) {$updatedMenuItems}");
        $this->line("Menu-menuItem links: {$createdMenuItemLinks}");

        return self::SUCCESS;
    }

    /**
     * @return array<string,string> normalized_english => arabic
     */
    private function parseTranslations(string $contents): array
    {
        $out = [];

        $start = strpos($contents, 'const TRANSLATIONS =');
        if ($start === false) {
            return $out;
        }
        $chunk = substr($contents, $start);

        $lines = preg_split("/\r\n|\n|\r/", $chunk) ?: [];
        $in = false;
        foreach ($lines as $line) {
            if (!$in) {
                if (str_contains($line, 'const TRANSLATIONS') && str_contains($line, '{')) {
                    $in = true;
                }
                continue;
            }
            if (str_contains($line, '};')) {
                break;
            }
            // Match: 'Key': 'Value',
            if (preg_match("/'([^']+)'\s*:\s*'([^']*)'\s*,?/", $line, $m)) {
                $out[$this->norm($m[1])] = $m[2];
            }
        }

        return $out;
    }

    /**
     * @return array<int, array{key:string,enDay?:string,arDay?:string,salad?:string,dessert?:string,mains:array<int,string>}>
     */
    private function parseMenuDays(string $contents): array
    {
        $out = [];

        // Capture each object literal in MENU_DAYS.
        $pattern = '/\{\s*key:\s*"(?<key>\d{4}-\d{2}-\d{2})"\s*,\s*enDay:\s*"(?<enDay>[^"]*)"\s*,\s*arDay:\s*"(?<arDay>[^"]*)"\s*,\s*salad:\s*"(?<salad>[^"]*)"\s*,\s*dessert:\s*"(?<dessert>[^"]*)"\s*,\s*mains:\s*\[(?<mains>[^\]]*)\]\s*\}/m';
        if (!preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
            return $out;
        }

        foreach ($matches as $m) {
            $mains = [];
            if (preg_match_all('/"([^"]+)"/', (string) ($m['mains'] ?? ''), $mm)) {
                $mains = array_values(array_filter(array_map('trim', $mm[1]), fn ($x) => $x !== ''));
            }

            $out[] = [
                'key' => (string) $m['key'],
                'enDay' => (string) $m['enDay'],
                'arDay' => (string) $m['arDay'],
                'salad' => (string) $m['salad'],
                'dessert' => (string) $m['dessert'],
                'mains' => $mains,
            ];
        }

        return $out;
    }

    private function getOrCreateMenuItem(
        string $name,
        array $translations,
        array &$byNorm,
        bool $hasStatusCol,
        bool $dryRun,
        int &$created,
        int &$updated
    ): MenuItem {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        $norm = $this->norm($name);

        $arabic = $translations[$norm] ?? null;

        if (isset($byNorm[$norm])) {
            /** @var MenuItem $mi */
            $mi = $byNorm[$norm];

            $needsSave = false;
            if ($arabic && (! $mi->arabic_name || trim((string) $mi->arabic_name) === '')) {
                $mi->arabic_name = $arabic;
                $needsSave = true;
            }

            if ($hasStatusCol && $mi->getAttribute('status') === null) {
                $mi->forceFill(['status' => 'active']);
                $needsSave = true;
            }

            if ($needsSave) {
                $updated++;
                if (! $dryRun) {
                    $mi->save();
                }
            }

            return $mi;
        }

        $created++;
        $code = $this->generateMenuItemCode($dryRun);

        $mi = new MenuItem();
        $mi->fill([
            'code' => $code,
            'name' => $name,
            'arabic_name' => $arabic,
            'category_id' => null,
            'recipe_id' => null,
            'selling_price_per_unit' => 0,
            'tax_rate' => 0,
            'is_active' => 1,
            'display_order' => 0,
        ]);

        if ($hasStatusCol) {
            $mi->forceFill(['status' => 'active']);
        }

        if (! $dryRun) {
            $mi->save();
        } else {
            $mi->id = -1;
        }

        $byNorm[$norm] = $mi;

        return $mi;
    }

    private function generateMenuItemCode(bool $dryRun): string
    {
        // Prefer MI- style codes to fit the existing DB.
        // We only need uniqueness; in dry-run we can return any deterministic value.
        if ($dryRun) {
            return 'MI-DRYRUN';
        }

        do {
            $code = 'MI-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (MenuItem::query()->where('code', $code)->exists());

        return $code;
    }

    private function norm(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        $s = Str::lower($s);
        return $s;
    }
}



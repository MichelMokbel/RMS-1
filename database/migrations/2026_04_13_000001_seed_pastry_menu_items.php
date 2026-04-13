<?php

use App\Models\MenuItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $categoryId = DB::table('categories')
            ->where('name', 'Pastry Items')
            ->whereNull('deleted_at')
            ->value('id');

        if (! $categoryId) {
            return;
        }

        $now   = now();
        $items = $this->items($categoryId, $now);

        // Use the Eloquent model so the auto-code generator fires on each row.
        foreach ($items as $item) {
            $exists = DB::table('menu_items')
                ->where('name', $item['name'])
                ->where('category_id', $categoryId)
                ->exists();

            if (! $exists) {
                MenuItem::create($item);
            }
        }
    }

    public function down(): void
    {
        $categoryId = DB::table('categories')
            ->where('name', 'Pastry Items')
            ->whereNull('deleted_at')
            ->value('id');

        if ($categoryId) {
            DB::table('menu_items')->where('category_id', $categoryId)->delete();
        }
    }

    // -------------------------------------------------------------------------

    private function items(int $categoryId, $now): array
    {
        $base = [
            'category_id' => $categoryId,
            'unit'        => 'each',
            'tax_rate'    => '0.00',
            'is_active'   => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        $sort = 364; // continues from existing max display_order

        $rows = [

            // ── Individual items ─────────────────────────────────────────────
            ['name' => 'Cake Pops - Normal Design',  'selling_price_per_unit' =>   8.000],
            ['name' => 'Cake Pops - 3D Design',      'selling_price_per_unit' =>   8.000],
            ['name' => 'Cupcake - 2D',               'selling_price_per_unit' =>   5.000],
            ['name' => 'Cupcake - 3D',               'selling_price_per_unit' =>   8.000],
            ['name' => 'Decorated Cookies',          'selling_price_per_unit' =>   7.000],
            ['name' => 'Macarons Normal',            'selling_price_per_unit' =>   5.000],

            // ── Basic Cakes ───────────────────────────────────────────────────
            ['name' => 'Basic Cake - Black Forest 10"',  'selling_price_per_unit' => 100.000],
            ['name' => 'Basic Cake - Red Velvet 10"',    'selling_price_per_unit' => 100.000],
            ['name' => 'Basic Cake - White Forest 10"',  'selling_price_per_unit' => 100.000],
            ['name' => 'Basic Cake - Black Forest 12"',  'selling_price_per_unit' => 145.000],
            ['name' => 'Basic Cake - Red Velvet 12"',    'selling_price_per_unit' => 145.000],
            ['name' => 'Basic Cake - White Forest 12"',  'selling_price_per_unit' => 145.000],

            // ── Basic Cakes with Edible Photo ─────────────────────────────────
            ['name' => 'Basic Cake with Photo - Black Forest 10"',  'selling_price_per_unit' => 135.000],
            ['name' => 'Basic Cake with Photo - Red Velvet 10"',    'selling_price_per_unit' => 135.000],
            ['name' => 'Basic Cake with Photo - White Forest 10"',  'selling_price_per_unit' => 135.000],
            ['name' => 'Basic Cake with Photo - Black Forest 12"',  'selling_price_per_unit' => 185.000],
            ['name' => 'Basic Cake with Photo - Red Velvet 12"',    'selling_price_per_unit' => 185.000],
            ['name' => 'Basic Cake with Photo - White Forest 12"',  'selling_price_per_unit' => 185.000],

            // ── 2D Single-tier Cakes (Choc / Vanilla / Nutella / Straw / Mango) ──
            ['name' => '2D Cake 6"  - Choc/Vanilla/Nutella/Strawberry/Mango',  'selling_price_per_unit' => 245.000],
            ['name' => '2D Cake 8"  - Choc/Vanilla/Nutella/Strawberry/Mango',  'selling_price_per_unit' => 295.000],
            ['name' => '2D Cake 10" - Choc/Vanilla/Nutella/Strawberry/Mango',  'selling_price_per_unit' => 350.000],
            ['name' => '2D Cake 12" - Choc/Vanilla/Nutella/Strawberry/Mango',  'selling_price_per_unit' => 450.000],

            // ── 3D Single-tier Cakes (Choc / Vanilla / Nutella / Straw / Mango) ──
            ['name' => '3D Cake 6"  - Choc/Vanilla/Nutella/Strawberry/Mango',  'selling_price_per_unit' => 235.000],
            ['name' => '3D Cake 8"  - Choc/Vanilla/Nutella/Strawberry/Mango',  'selling_price_per_unit' => 250.000],
            ['name' => '3D Cake 10" - Choc/Vanilla/Nutella/Strawberry/Mango',  'selling_price_per_unit' => 300.000],
            ['name' => '3D Cake 12" - Choc/Vanilla/Nutella/Strawberry/Mango',  'selling_price_per_unit' => 395.000],

            // ── 3D 2-Layer Decorative Cakes ───────────────────────────────────
            ['name' => '3D 2-Layer Decorative Cake 8"&6"   - Choc/Vanilla/Nutella/Strawberry/Mango',  'selling_price_per_unit' => 495.000],
            ['name' => '3D 2-Layer Decorative Cake 10"&8"  - Choc/Vanilla/Nutella/Strawberry/Mango',  'selling_price_per_unit' => 595.000],
            ['name' => '3D 2-Layer Decorative Cake 12"&10" - Choc/Vanilla/Nutella/Strawberry/Mango',  'selling_price_per_unit' => 715.000],

            // ── 2D 3-Layer Decorative Cakes ───────────────────────────────────
            ['name' => '2D 3-Layer Decorative Cake 10"/8"/6"  - Choc/Vanilla/Nutella/Strawberry/Mango',  'selling_price_per_unit' => 735.000],
            ['name' => '2D 3-Layer Decorative Cake 12"/10"/6" - Choc/Vanilla/Nutella/Strawberry/Mango',  'selling_price_per_unit' => 830.000],

            // ── Croissants ────────────────────────────────────────────────────
            ['name' => 'Croissant - Cheese',    'selling_price_per_unit' =>  4.000],
            ['name' => 'Croissant - Chocolate', 'selling_price_per_unit' =>  4.000],
            ['name' => 'Croissant - Plain',     'selling_price_per_unit' =>  4.000],
            ['name' => 'Croissant - Zaatar',    'selling_price_per_unit' =>  4.000],

            // ── Mini Cheesecakes ──────────────────────────────────────────────
            ['name' => 'Mini Cheesecake 6" - Lotus',      'selling_price_per_unit' => 70.000],
            ['name' => 'Mini Cheesecake 6" - Blueberry',  'selling_price_per_unit' => 70.000],
            ['name' => 'Mini Cheesecake 6" - Strawberry', 'selling_price_per_unit' => 70.000],

            // ── Mini Cakes ────────────────────────────────────────────────────
            ['name' => 'Mini Cake 6" - Dark Chocolate', 'selling_price_per_unit' => 60.000],
            ['name' => 'Mini Cake 6" - Carrot',         'selling_price_per_unit' => 60.000],
            ['name' => 'Mini Cake 6" - Red Velvet',     'selling_price_per_unit' => 60.000],

            // ── Other ─────────────────────────────────────────────────────────
            ['name' => 'Cake Slice', 'selling_price_per_unit' => 12.000],
        ];

        return array_map(function (array $row) use ($base, &$sort) {
            return array_merge($base, $row, ['display_order' => $sort++]);
        }, $rows);
    }
};

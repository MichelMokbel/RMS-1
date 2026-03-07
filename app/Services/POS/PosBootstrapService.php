<?php

namespace App\Services\POS;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\MenuItem;
use App\Models\PettyCashWallet;
use App\Models\RestaurantArea;
use App\Models\RestaurantTable;
use App\Models\RestaurantTableSession;
use App\Support\Money\MinorUnits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class PosBootstrapService
{
    /**
     * @param  \App\Models\PosTerminal  $terminal
     */
    public function bootstrap($terminal, ?string $since = null): array
    {
        $sinceAt = $since ? Carbon::parse($since)->utc() : null;

        $branchId = (int) $terminal->branch_id;
        $scale = MinorUnits::posScale();

        $categories = Category::query()
            ->when($sinceAt, fn ($q) => $this->whereUpdatedSince($q, 'categories', $sinceAt))
            ->orderBy('name')
            ->get()
            ->map(fn (Category $c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'description' => $c->description !== null ? (string) $c->description : null,
                'parent_id' => $c->parent_id ? (int) $c->parent_id : null,
                'updated_at' => optional($c->updated_at)?->toISOString(),
            ])
            ->values();

        $menuItems = MenuItem::query()
            ->availableInBranch($branchId)
            ->when($sinceAt, fn ($q) => $this->whereUpdatedSince($q, 'menu_items', $sinceAt))
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(function (MenuItem $m) use ($scale) {
                $priceCents = MinorUnits::parse((string) ($m->selling_price_per_unit ?? '0'), $scale);

                return [
                    'id' => (int) $m->id,
                    'code' => (string) $m->code,
                    'name' => (string) $m->name,
                    'arabic_name' => (string) ($m->arabic_name ?? ''),
                    'category_id' => (int) $m->category_id,
                    'unit' => (string) ($m->unit ?? 'each'),
                    'is_active' => (bool) $m->is_active,
                    'tax_rate' => (string) ($m->tax_rate ?? '0'),
                    'price_cents' => $priceCents,
                    'updated_at' => optional($m->updated_at)?->toISOString(),
                ];
            })
            ->values();

        $customers = Customer::query()
            ->when($sinceAt, fn ($q) => $this->whereUpdatedSince($q, 'customers', $sinceAt))
            ->orderBy('name')
            ->limit(5000)
            ->get()
            ->map(fn (Customer $c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'phone' => (string) ($c->phone ?? ''),
                'email' => (string) ($c->email ?? ''),
                'is_active' => (bool) $c->is_active,
                'updated_at' => optional($c->updated_at)?->toISOString(),
            ])
            ->values();

        $areas = RestaurantArea::query()
            ->where('branch_id', $branchId)
            ->when($sinceAt, fn ($q) => $this->whereUpdatedSince($q, 'restaurant_areas', $sinceAt))
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(fn (RestaurantArea $a) => [
                'id' => (int) $a->id,
                'name' => (string) $a->name,
                'display_order' => (int) $a->display_order,
                'active' => (bool) $a->active,
                'updated_at' => optional($a->updated_at)?->toISOString(),
            ])
            ->values();

        $tables = RestaurantTable::query()
            ->where('branch_id', $branchId)
            ->when($sinceAt, fn ($q) => $this->whereUpdatedSince($q, 'restaurant_tables', $sinceAt))
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(fn (RestaurantTable $t) => [
                'id' => (int) $t->id,
                'area_id' => $t->area_id ? (int) $t->area_id : null,
                'code' => (string) $t->code,
                'name' => (string) $t->name,
                'capacity' => $t->capacity ? (int) $t->capacity : null,
                'display_order' => (int) $t->display_order,
                'active' => (bool) $t->active,
                'updated_at' => optional($t->updated_at)?->toISOString(),
            ])
            ->values();

        $sessions = RestaurantTableSession::query()
            ->where('branch_id', $branchId)
            ->when($sinceAt, fn ($q) => $this->whereUpdatedSince($q, 'restaurant_table_sessions', $sinceAt))
            ->when(! $sinceAt, fn ($q) => $q->where('active', 1))
            ->orderByDesc('opened_at')
            ->limit(5000)
            ->get()
            ->map(fn (RestaurantTableSession $s) => [
                'id' => (int) $s->id,
                'table_id' => (int) $s->table_id,
                'status' => (string) $s->status,
                'active' => (bool) $s->active,
                'opened_at' => optional($s->opened_at)?->toISOString(),
                'closed_at' => optional($s->closed_at)?->toISOString(),
                'guests' => $s->guests ? (int) $s->guests : null,
                'terminal_id' => $s->terminal_id ? (int) $s->terminal_id : null,
                'device_id' => $s->device_id,
                'pos_shift_id' => $s->pos_shift_id ? (int) $s->pos_shift_id : null,
                'updated_at' => optional($s->updated_at)?->toISOString(),
            ])
            ->values();

        $wallets = class_exists(PettyCashWallet::class) ? PettyCashWallet::query()
            ->when(Schema::hasColumn('petty_cash_wallets', 'branch_id'), fn ($q) => $q->where('branch_id', $branchId))
            ->when($sinceAt, fn ($q) => $this->whereUpdatedSince($q, 'petty_cash_wallets', $sinceAt))
            ->orderBy('driver_name')
            ->get()
            ->map(fn (PettyCashWallet $w) => [
                'id' => (int) $w->id,
                'name' => (string) $w->driver_name,
                'active' => (bool) $w->active,
                'balance' => (string) ($w->balance ?? '0.00'),
                'created_at' => optional($w->created_at)?->toISOString(),
            ])
            ->values() : collect();

        $expenseCategories = ExpenseCategory::query()
            ->when($sinceAt, fn ($q) => $this->whereUpdatedSince($q, 'expense_categories', $sinceAt))
            ->orderBy('name')
            ->get()
            ->map(fn (ExpenseCategory $c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'active' => (bool) ($c->active ?? true),
                'created_at' => optional($c->created_at)?->toISOString(),
            ])
            ->values();

        $receiptProfile = $this->buildReceiptProfile($terminal);

        return [
            'settings' => [
                'currency' => (string) config('pos.currency', 'QAR'),
                'money_scale' => (int) config('pos.money_scale', 100),
            ],
            'terminal' => [
                'id' => (int) $terminal->id,
                'code' => (string) $terminal->code,
                'branch_id' => (int) $terminal->branch_id,
            ],
            'receipt_profile' => $receiptProfile,
            'categories' => $categories,
            'menu_items' => $menuItems,
            'customers' => $customers,
            'restaurant_areas' => $areas,
            'restaurant_tables' => $tables,
            'restaurant_table_sessions' => $sessions,
            'petty_cash_wallets' => $wallets,
            'expense_categories' => $expenseCategories,
            'server_timestamp' => now()->utc()->toISOString(),
        ];
    }

    private function buildReceiptProfile($terminal): array
    {
        $configured = (array) config('pos.receipt_profile', []);
        $branchId = (int) ($terminal->branch_id ?? 0);
        $branchNameFromDb = (string) (Branch::query()->whereKey($branchId)->value('name') ?? '');

        $branchNameEn = trim((string) ($configured['branch_name_en'] ?? ''));
        if ($branchNameEn === '') {
            $branchNameEn = $branchNameFromDb;
        }

        $timezone = trim((string) ($configured['timezone'] ?? ''));
        if ($timezone === '') {
            $timezone = (string) config('app.timezone', 'UTC');
        }

        return [
            'brand_name_en' => (string) ($configured['brand_name_en'] ?? ''),
            'brand_name_ar' => (string) ($configured['brand_name_ar'] ?? ''),
            'legal_name_en' => (string) ($configured['legal_name_en'] ?? ''),
            'legal_name_ar' => (string) ($configured['legal_name_ar'] ?? ''),
            'branch_name_en' => $branchNameEn,
            'branch_name_ar' => (string) ($configured['branch_name_ar'] ?? ''),
            'address_lines_en' => $this->normalizeReceiptLines($configured['address_lines_en'] ?? []),
            'address_lines_ar' => $this->normalizeReceiptLines($configured['address_lines_ar'] ?? []),
            'phone' => (string) ($configured['phone'] ?? ''),
            'logo_url' => (string) ($configured['logo_url'] ?? ''),
            'footer_note_en' => (string) ($configured['footer_note_en'] ?? ''),
            'footer_note_ar' => (string) ($configured['footer_note_ar'] ?? ''),
            'timezone' => $timezone,
        ];
    }

    private function normalizeReceiptLines($lines): array
    {
        if (! is_array($lines)) {
            $lines = explode('|', (string) $lines);
        }

        return array_values(array_filter(
            array_map(static fn ($line) => trim((string) $line), $lines),
            static fn (string $line) => $line !== ''
        ));
    }

    private function whereUpdatedSince($query, string $table, Carbon $sinceAt)
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'updated_at')) {
            return $query;
        }
        return $query->where("{$table}.updated_at", '>', $sinceAt);
    }
}

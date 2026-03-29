<?php

namespace App\Services\Menu;

use App\Models\MenuItem;
use App\Services\Sequences\DocumentSequenceService;
use Illuminate\Support\Facades\Schema;

class MenuItemCodeService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
    ) {
    }

    public function nextCode(): string
    {
        $sequence = $this->sequences->nextWithSeed(
            'menu_item_code',
            1,
            '0000',
            $this->maxNumericCode()
        );

        return $this->format($sequence);
    }

    public function previewCode(): string
    {
        return $this->format($this->maxNumericCode() + 1);
    }

    public function format(int $number): string
    {
        return 'MI-'.str_pad((string) max(1, $number), 6, '0', STR_PAD_LEFT);
    }

    public function maxNumericCode(): int
    {
        if (! Schema::hasTable('menu_items')) {
            return 0;
        }

        return (int) (MenuItem::query()
            ->whereNotNull('code')
            ->whereRaw("code REGEXP '^MI-[0-9]+$'")
            ->selectRaw('MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) as max_code')
            ->value('max_code') ?? 0);
    }
}

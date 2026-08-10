<?php

namespace App\Services\Quotations\Rendering;

final class QuotationBlockLayout
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array{type: 'row', cells: list<array{block: array<string, mixed>, span: int}>, used_columns: int}|array{type: 'page_break'}>
     */
    public function rows(array $blocks): array
    {
        $rows = [];
        $cells = [];
        $usedColumns = 0;

        $flush = function () use (&$rows, &$cells, &$usedColumns): void {
            if ($cells === []) {
                return;
            }

            $rows[] = ['type' => 'row', 'cells' => $cells, 'used_columns' => $usedColumns];
            $cells = [];
            $usedColumns = 0;
        };

        foreach ($blocks as $block) {
            if ($block['type'] === 'page_break') {
                $flush();
                $rows[] = ['type' => 'page_break'];

                continue;
            }

            $span = (int) $block['column_span'];
            if (($block['new_row'] && $cells !== []) || $usedColumns + $span > 12) {
                $flush();
            }

            $cells[] = ['block' => $block, 'span' => $span];
            $usedColumns += $span;

            if ($usedColumns === 12) {
                $flush();
            }
        }
        $flush();

        return $rows;
    }
}

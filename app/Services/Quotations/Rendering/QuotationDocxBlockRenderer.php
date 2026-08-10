<?php

namespace App\Services\Quotations\Rendering;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Table as TableStyle;

final class QuotationDocxBlockRenderer
{
    public function __construct(private readonly QuotationAssetResolver $assets) {}

    public function render(
        Cell $cell,
        array $document,
        array $block,
        string $direction,
        ?string $alignment,
        int $cellWidth,
    ): void {
        match ($block['type']) {
            'company_header' => $this->companyHeader($cell, $document, $block, $direction, $alignment, $cellWidth),
            'quotation_metadata' => $this->quotationMetadata($cell, $document, $direction, $alignment),
            'recipient_details' => $this->recipientDetails($cell, $document, $direction, $alignment),
            'menu_pricing' => $this->menuPricing($cell, $document, $direction),
            'rich_text' => $this->tiptap($cell, $block['content'] ?? [], $direction, $alignment),
            'items_table' => $this->itemsTable($cell, $document, $direction, $cellWidth),
            'totals' => $this->totals($cell, $document, $direction, $cellWidth),
            'terms' => $this->terms($cell, $document, $block, $direction, $alignment),
            'signature_lines' => $this->signatures($cell, $block, $direction, $cellWidth),
            'image' => $this->image($cell, $document, $block, $direction, $alignment),
            'spacer' => $cell->addText('', [], ['spaceAfter' => Converter::cmToTwip(min(100, max(2, (float) ($block['height_mm'] ?? 8))) / 10)]),
            default => null,
        };
    }

    public function renderFreeForm(
        AbstractContainer $container,
        array $document,
        mixed $content,
        string $direction,
        ?string $alignment,
    ): void {
        $this->tiptap($container, $content, $direction, $alignment, $document, true);
    }

    public function renderStandaloneCompanyHeader(
        AbstractContainer $container,
        array $document,
        array $block,
        string $direction,
        ?string $alignment,
        int $width,
    ): void {
        $this->companyHeader($container, $document, $block, $direction, $alignment, $width);
    }

    private function companyHeader(AbstractContainer $cell, array $document, array $block, string $direction, ?string $alignment, int $width): void
    {
        $company = $document['company_profile'];
        $alignment = $this->logicalAlignment($block['company_alignment'] ?? 'start', $direction);
        $asset = $this->assets->contents($document, $company['logo_asset_id'] ?? null);
        $showLogo = (! array_key_exists('show_logo', $block) || (bool) $block['show_logo']) && $asset !== null;
        if (($block['show_details'] ?? true) === false) {
            if ($showLogo) {
                $cell->addImage($asset['contents'], ['width' => 120, 'alignment' => 'center']);
            }

            return;
        }
        if ($showLogo && $block['logo_position'] === 'top') {
            $cell->addImage($asset['contents'], ['width' => 120, 'alignment' => $alignment ?? 'left']);
            $this->companyCopy($cell, $company, $direction, $alignment);

            return;
        }

        if ($showLogo) {
            $table = $cell->addTable(['width' => $width, 'unit' => TblWidth::TWIP, 'layout' => TableStyle::LAYOUT_FIXED]);
            $row = $table->addRow();
            if ($block['logo_position'] === 'end') {
                $copy = $row->addCell((int) round($width * 0.78), ['noWrap' => false, 'valign' => 'top']);
                $logo = $row->addCell((int) round($width * 0.22), ['noWrap' => false, 'valign' => 'top']);
            } else {
                $logo = $row->addCell((int) round($width * 0.22), ['noWrap' => false, 'valign' => 'top']);
                $copy = $row->addCell((int) round($width * 0.78), ['noWrap' => false, 'valign' => 'top']);
            }
            $logo->addImage($asset['contents'], ['width' => 120]);
            $this->companyCopy($copy, $company, $direction, $alignment);

            return;
        }

        $this->companyCopy($cell, $company, $direction, $alignment);
    }

    private function companyCopy(AbstractContainer $container, array $company, string $direction, ?string $alignment): void
    {
        $names = array_filter([
            $this->text($company['legal_name_en'] ?? $company['company_name'] ?? $company['name'] ?? ''),
            $this->text($company['legal_name_ar'] ?? ''),
        ]);
        $details = array_filter([
            $this->text($company['address_en'] ?? $company['address'] ?? ''),
            $this->text($company['address_ar'] ?? ''),
            $this->text($company['phone'] ?? ''),
            $this->text($company['email'] ?? ''),
            $this->text($company['website'] ?? ''),
            $this->text($company['commercial_registration'] ?? $company['registration_number'] ?? ''),
            $this->text($company['tax_registration'] ?? ''),
        ]);

        $container->addText(implode("\n", $names), ['bold' => true, 'size' => 19, 'rtl' => $direction === 'rtl'], $this->paragraph($direction, $alignment));
        if ($details !== []) {
            $container->addText(implode("\n", $details), ['size' => 9, 'color' => '475569', 'rtl' => $direction === 'rtl'], $this->paragraph($direction, $alignment));
        }
    }

    private function quotationMetadata(Cell $cell, array $document, string $direction, ?string $alignment): void
    {
        $quote = $document['quotation'];
        $this->heading($cell, __('Quotation'), 16, $direction, $alignment);
        foreach ([
            __('Number') => $quote['number'] ?? $quote['quotation_number'] ?? __('Draft'),
            __('Issue date') => $quote['issue_date'] ?? '',
            __('Valid until') => $quote['valid_until'] ?? '',
            __('Currency') => $quote['currency'] ?? 'QAR',
        ] as $label => $value) {
            $run = $cell->addTextRun($this->paragraph($direction, $alignment));
            $run->addText($this->text($label).': ', ['bold' => true, 'rtl' => $direction === 'rtl']);
            $run->addText($this->text($value), ['rtl' => $direction === 'rtl']);
        }
    }

    private function recipientDetails(Cell $cell, array $document, string $direction, ?string $alignment): void
    {
        $recipient = $document['recipient'] !== [] ? $document['recipient'] : $document['customer'];
        $this->heading($cell, __('Quotation for'), 12, $direction, $alignment);
        foreach (array_filter([
            $this->text($recipient['name'] ?? $recipient['legal_name'] ?? ''),
            $this->text($recipient['contact_name'] ?? ''),
            $this->text($recipient['address'] ?? ''),
            $this->text($recipient['email'] ?? ''),
            $this->text($recipient['phone'] ?? ''),
        ]) as $line) {
            $cell->addText($line, ['rtl' => $direction === 'rtl'], $this->paragraph($direction, $alignment));
        }
    }

    private function menuPricing(AbstractContainer $cell, array $document, string $direction, ?string $alignment = 'center'): void
    {
        $item = $document['items'][0] ?? null;
        $paragraph = $this->paragraph($direction, $alignment);
        $price = $cell->addTextRun($paragraph);
        if ($item === null) {
            $price->addText(__('Price per person:').' ', ['rtl' => $direction === 'rtl']);
            $price->addText(__('To be confirmed'), ['italic' => true, 'color' => '64748B', 'rtl' => $direction === 'rtl']);
        } else {
            $price->addText(__('Price:').' QAR '.$this->compactMoney((int) $item['unit_price_minor']).' '.__('per person'), ['bold' => true, 'size' => 13, 'color' => '334155', 'rtl' => $direction === 'rtl']);
        }

        $minimum = $cell->addTextRun($paragraph);
        if ($item !== null && (float) $item['quantity'] > 0) {
            $minimum->addText(__('Minimum Order:').' '.$this->compactQuantity($item['quantity']).' '.$this->personLabel($item['quantity']), ['bold' => true, 'rtl' => $direction === 'rtl']);
        } else {
            $minimum->addText(__('Minimum order:').' ', ['rtl' => $direction === 'rtl']);
            $minimum->addText(__('To be confirmed'), ['italic' => true, 'color' => '64748B', 'rtl' => $direction === 'rtl']);
        }
    }

    private function tiptap(
        AbstractContainer $cell,
        mixed $content,
        string $direction,
        ?string $alignment,
        ?array $document = null,
        bool $freeForm = false,
    ): void {
        if (! is_array($content)) {
            return;
        }

        foreach ((array) ($content['content'] ?? []) as $node) {
            if (! is_array($node)) {
                continue;
            }

            $nodeDirection = $this->nodeDirection($node, $direction);
            if ($freeForm && $document !== null && $this->renderFreeFormNode(
                $cell,
                $document,
                $node,
                $nodeDirection,
                $alignment,
            )) {
                continue;
            }

            if (in_array($node['type'] ?? null, ['bulletList', 'orderedList'], true)) {
                $this->list($cell, $node, $nodeDirection, $alignment, $document, $freeForm);

                continue;
            }

            if (($node['type'] ?? null) === 'blockquote') {
                $this->tiptap(
                    $cell,
                    ['type' => 'doc', 'content' => (array) ($node['content'] ?? [])],
                    $nodeDirection,
                    $alignment,
                    $document,
                    $freeForm,
                );

                continue;
            }

            if (! in_array($node['type'] ?? null, ['paragraph', 'heading'], true)) {
                continue;
            }

            $nodeAlignment = $this->nodeAlignment($node, $alignment);
            $run = $cell->addTextRun($this->paragraph($nodeDirection, $nodeAlignment));
            $headingSize = ($node['type'] ?? null) === 'heading'
                ? $this->headingSize($node['attrs']['level'] ?? 2)
                : null;
            $this->inline($run, (array) ($node['content'] ?? []), $nodeDirection, $headingSize);
        }
    }

    private function renderFreeFormNode(
        AbstractContainer $container,
        array $document,
        array $node,
        string $direction,
        ?string $alignment,
    ): bool {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

        return match ($node['type'] ?? null) {
            'quotationPricing' => (function () use ($container, $document, $direction, $alignment, $attrs): bool {
                $this->menuPricing(
                    $container,
                    $document,
                    $direction,
                    $this->nodePhysicalAlignment($attrs['alignment'] ?? null, $direction, $alignment ?? 'center'),
                );

                return true;
            })(),
            'quotationImage' => (function () use ($container, $document, $direction, $alignment, $attrs): bool {
                $this->image(
                    $container,
                    $document,
                    [
                        'asset_id' => $attrs['assetId'] ?? $attrs['asset_id'] ?? null,
                        'width_mm' => $attrs['widthMm'] ?? $attrs['width_mm'] ?? 85,
                    ],
                    $direction,
                    $this->nodePhysicalAlignment($attrs['alignment'] ?? null, $direction, $alignment),
                );

                return true;
            })(),
            'documentSpacer' => (function () use ($container, $attrs): bool {
                $height = min(100, max(2, (float) ($attrs['heightMm'] ?? $attrs['height_mm'] ?? 8)));
                $container->addText('', [], ['spaceAfter' => Converter::cmToTwip($height / 10)]);

                return true;
            })(),
            'pageBreak' => (function () use ($container): bool {
                $container->addPageBreak();

                return true;
            })(),
            default => false,
        };
    }

    private function list(
        AbstractContainer $cell,
        array $list,
        string $direction,
        ?string $alignment,
        ?array $document = null,
        bool $freeForm = false,
    ): void {
        $ordered = ($list['type'] ?? null) === 'orderedList';
        foreach (array_values((array) ($list['content'] ?? [])) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $run = $cell->addTextRun($this->paragraph($direction, $alignment));
            $run->addText($ordered ? ($index + 1).'. ' : '• ', ['rtl' => $direction === 'rtl']);
            foreach ((array) ($item['content'] ?? []) as $child) {
                if (! is_array($child)) {
                    continue;
                }
                if (in_array($child['type'] ?? null, ['paragraph', 'heading'], true)) {
                    $this->inline($run, (array) ($child['content'] ?? []), $this->nodeDirection($child, $direction), null);
                } elseif (in_array($child['type'] ?? null, ['bulletList', 'orderedList'], true)) {
                    $this->list($cell, $child, $this->nodeDirection($child, $direction), $alignment, $document, $freeForm);
                }
            }
        }
    }

    private function inline(TextRun $run, array $nodes, string $direction, ?int $headingSize): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) === 'hardBreak') {
                $run->addTextBreak();

                continue;
            }
            if (($node['type'] ?? null) !== 'text') {
                continue;
            }

            $font = [
                'bold' => $headingSize !== null,
                'size' => $headingSize ?? 10,
                'rtl' => $direction === 'rtl',
            ];
            $href = null;
            foreach ((array) ($node['marks'] ?? []) as $mark) {
                if (! is_array($mark)) {
                    continue;
                }
                match ($mark['type'] ?? null) {
                    'bold' => $font['bold'] = true,
                    'italic' => $font['italic'] = true,
                    'underline' => $font['underline'] = 'single',
                    'link' => $href = $this->safeLink($mark['attrs']['href'] ?? null),
                    default => null,
                };
            }
            $text = $this->text($node['text'] ?? '');
            $href !== null ? $run->addLink($href, $text, $font) : $run->addText($text, $font);
        }
    }

    private function itemsTable(Cell $cell, array $document, string $direction, int $width): void
    {
        $columns = $document['settings']['table_columns'];
        $definitions = [
            'description' => [__('Description'), 3.5], 'unit' => [__('Unit'), 1.5], 'quantity' => [__('Qty'), 1.25],
            'unit_price' => [__('Unit price'), 2], 'discount' => [__('Discount'), 1.5], 'total' => [__('Total'), 2.25],
        ];
        $visible = array_filter($definitions, fn ($definition, $key) => ! array_key_exists($key, $columns) || (bool) $columns[$key], ARRAY_FILTER_USE_BOTH);
        $weight = array_sum(array_column($visible, 1));
        $table = $cell->addTable(['width' => $width, 'unit' => TblWidth::TWIP, 'layout' => TableStyle::LAYOUT_FIXED, 'bidiVisual' => $direction === 'rtl']);
        $header = $table->addRow();
        foreach ($visible as [$label, $columnWeight]) {
            $header->addCell((int) round($width * $columnWeight / $weight), ['bgColor' => '334155', 'noWrap' => false])
                ->addText($this->text($label), ['bold' => true, 'color' => 'FFFFFF', 'rtl' => $direction === 'rtl']);
        }
        foreach ($document['items'] as $item) {
            $values = [
                'description' => $item['description'], 'unit' => $item['unit'], 'quantity' => $item['quantity'],
                'unit_price' => $this->money($item['unit_price_minor']), 'discount' => $this->money($item['line_discount_minor']),
                'total' => $this->money($item['line_total_minor']),
            ];
            $row = $table->addRow();
            foreach ($visible as $key => [, $columnWeight]) {
                $row->addCell((int) round($width * $columnWeight / $weight), ['noWrap' => false])
                    ->addText($this->text($values[$key]), ['rtl' => $direction === 'rtl']);
            }
        }
    }

    private function totals(Cell $cell, array $document, string $direction, int $width): void
    {
        $table = $cell->addTable(['width' => $width, 'unit' => TblWidth::TWIP, 'layout' => TableStyle::LAYOUT_FIXED, 'bidiVisual' => $direction === 'rtl']);
        foreach ([
            __('Subtotal') => $document['totals']['subtotal_minor'], __('Line discounts') => $document['totals']['line_discount_minor'],
            __('Quotation discount') => $document['totals']['quotation_discount_minor'], __('Total') => $document['totals']['total_minor'],
        ] as $label => $amount) {
            $row = $table->addRow();
            $row->addCell((int) round($width * 0.65), ['noWrap' => false])->addText($this->text($label), ['bold' => true, 'rtl' => $direction === 'rtl']);
            $row->addCell((int) round($width * 0.35), ['noWrap' => false])->addText($this->money($amount), ['rtl' => $direction === 'rtl']);
        }
    }

    private function terms(Cell $cell, array $document, array $block, string $direction, ?string $alignment): void
    {
        $this->heading($cell, __('Terms'), 12, $direction, $alignment);
        $content = $block['content'] ?? $document['company_profile']['default_terms'] ?? null;
        if (is_array($content)) {
            $this->tiptap($cell, $content, $direction, $alignment);
        } else {
            $text = $this->text($content ?? implode("\n", array_filter([
                $this->text($document['company_profile']['default_terms_en'] ?? ''),
                $this->text($document['company_profile']['default_terms_ar'] ?? ''),
            ])));
            foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                $cell->addText($line, ['rtl' => $direction === 'rtl'], $this->paragraph($direction, $alignment));
            }
        }
    }

    private function signatures(Cell $cell, array $block, string $direction, int $width): void
    {
        $labels = is_array($block['labels'] ?? null) ? array_values($block['labels']) : [];
        $table = $cell->addTable(['width' => $width, 'unit' => TblWidth::TWIP, 'layout' => TableStyle::LAYOUT_FIXED, 'bidiVisual' => $direction === 'rtl']);
        $row = $table->addRow();
        foreach ([$block['left_label'] ?? $labels[0] ?? __('Prepared by'), $block['right_label'] ?? $labels[1] ?? __('Accepted by')] as $label) {
            $signature = $row->addCell((int) round($width / 2), ['noWrap' => false]);
            $signature->addText($this->text($label), ['rtl' => $direction === 'rtl']);
            $signature->addText('____________________________');
        }
    }

    private function image(AbstractContainer $cell, array $document, array $block, string $direction, ?string $alignment): void
    {
        $asset = $this->assets->contents($document, $block['asset_id'] ?? null);
        if ($asset === null) {
            return;
        }
        $width = (int) round(min(180, max(10, (float) ($block['width_mm'] ?? 85))) * 3.7795275591);
        $cell->addImage($asset['contents'], ['width' => $width, 'alignment' => $alignment ?? ($direction === 'rtl' ? 'right' : 'left')]);
    }

    private function heading(AbstractContainer $container, mixed $text, int $size, string $direction, ?string $alignment): void
    {
        $container->addText($this->text($text), ['bold' => true, 'size' => $size, 'rtl' => $direction === 'rtl'], $this->paragraph($direction, $alignment));
    }

    /** @return array<string, bool|string> */
    private function paragraph(string $direction, ?string $alignment): array
    {
        return array_filter(['bidi' => $direction === 'rtl', 'alignment' => $alignment], fn ($value) => $value !== null);
    }

    private function nodeAlignment(array $node, ?string $fallback): ?string
    {
        return in_array($node['attrs']['textAlign'] ?? null, ['left', 'center', 'right', 'justify'], true)
            ? ($node['attrs']['textAlign'] === 'justify' ? 'both' : $node['attrs']['textAlign'])
            : $fallback;
    }

    private function nodeDirection(array $node, string $fallback): string
    {
        return in_array($node['attrs']['direction'] ?? null, ['auto', 'ltr', 'rtl'], true)
            ? $node['attrs']['direction']
            : $fallback;
    }

    private function nodePhysicalAlignment(mixed $alignment, string $direction, ?string $fallback): ?string
    {
        return match ($alignment) {
            'left', 'right', 'center' => $alignment,
            'start' => $direction === 'rtl' ? 'right' : 'left',
            'end' => $direction === 'rtl' ? 'left' : 'right',
            default => $fallback,
        };
    }

    private function headingSize(mixed $level): int
    {
        return match (min(3, max(1, (int) $level))) {
            1 => 18,
            2 => 15,
            default => 13,
        };
    }

    private function logicalAlignment(string $alignment, string $direction): string
    {
        return match ($alignment) {
            'center' => 'center',
            'end' => $direction === 'rtl' ? 'left' : 'right',
            default => $direction === 'rtl' ? 'right' : 'left',
        };
    }

    private function safeLink(mixed $href): ?string
    {
        if (! is_string($href) || strlen($href) > 2048) {
            return null;
        }
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) ? $href : null;
    }

    private function money(int $minor): string
    {
        return number_format($minor / 100, 2, '.', ',').' QAR';
    }

    private function compactMoney(int $minor): string
    {
        return $minor % 100 === 0
            ? number_format($minor / 100, 0, '.', ',')
            : number_format($minor / 100, 2, '.', ',');
    }

    private function compactQuantity(mixed $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 3, '.', ''), '0'), '.');
    }

    private function personLabel(mixed $quantity): string
    {
        return abs((float) $quantity - 1.0) < 0.0005 ? __('person') : __('persons');
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}

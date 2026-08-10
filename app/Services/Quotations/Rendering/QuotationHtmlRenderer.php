<?php

namespace App\Services\Quotations\Rendering;

use Illuminate\Contracts\View\Factory as ViewFactory;

final class QuotationHtmlRenderer
{
    public function __construct(
        private readonly QuotationSnapshot $snapshot,
        private readonly TiptapHtmlRenderer $richText,
        private readonly QuotationAssetResolver $assets,
        private readonly QuotationBlockLayout $layout,
        private readonly ViewFactory $views,
    ) {}

    public function render(array $snapshot): string
    {
        $normalized = $this->snapshot->normalize($snapshot);

        return $this->views->make('quotations.document.document', [
            'document' => $normalized,
            'body' => $this->renderLayout($normalized),
        ])->render();
    }

    public function renderBody(array $snapshot): string
    {
        $normalized = $this->snapshot->normalize($snapshot);

        return $this->renderLayout($normalized);
    }

    private function renderLayout(array $document): string
    {
        if ($document['settings']['document_mode'] === 'menu_proposal'
            && $document['settings']['menu_content_mode'] === 'free_form') {
            return $this->renderFreeFormMenu($document);
        }

        $html = '';
        $rowDirection = $this->resolvedDirection('auto', $document['settings']['default_direction']);
        foreach ($this->layout->rows($document['blocks']) as $row) {
            if ($row['type'] === 'page_break') {
                $html .= '<p class="page-break" style="page-break-after:always"></p>';

                continue;
            }

            $cells = array_map(function (array $cell) use ($document): array {
                $block = $cell['block'];
                $direction = $this->resolvedDirection($block['direction'], $document['settings']['default_direction']);

                return [
                    'html' => $this->renderBlockFragment($document, $block),
                    'span' => $cell['span'],
                    'alignment' => $this->cssAlignment($block['horizontal_alignment'], $direction),
                ];
            }, $row['cells']);
            $html .= $this->layoutRow($cells, $row['used_columns'], $rowDirection);
        }

        return $html;
    }

    /** @param list<array{html: string, span: int, alignment: ?string}> $cells */
    private function layoutRow(array $cells, int $usedColumns, string $direction): string
    {
        $html = '<table class="layout-row" role="presentation" dir="'.$direction.'" style="direction:'.$direction.';table-layout:fixed;width:100%"><tr>';
        foreach ($cells as $cell) {
            $width = $this->columnWidth($cell['span']);
            $alignment = $cell['alignment'] !== null ? ';text-align:'.$cell['alignment'] : '';
            $html .= '<td class="layout-cell" data-column-span="'.$cell['span'].'" width="'.$width.'%" style="vertical-align:top'.$alignment.'">'
                .'<p class="layout-anchor" aria-hidden="true"></p>'.$cell['html'].'</td>';
        }

        if ($usedColumns < 12) {
            $width = $this->columnWidth(12 - $usedColumns);
            $html .= '<td class="layout-empty" aria-hidden="true" width="'.$width.'%">&#160;</td>';
        }

        return $html.'</tr></table>';
    }

    public function renderBlockFragment(array $document, array $block): string
    {
        $direction = $this->resolvedDirection($block['direction'], $document['settings']['default_direction']);

        return match ($block['type']) {
            'company_header' => $this->companyHeader($document, $block, $direction),
            'quotation_metadata' => $this->quotationMetadata($document, $direction),
            'recipient_details' => $this->recipientDetails($document, $direction),
            'menu_pricing' => $this->menuPricing($document, $direction),
            'menu_body' => $this->renderMenuBody($document, $block, $direction),
            'rich_text' => '<section class="rich-text" dir="'.$direction.'" style="direction:'.$direction.'">'
                .$this->richText->render($block['content'] ?? [], $direction).'</section>',
            'items_table' => $this->itemsTable($document, $direction),
            'totals' => $this->totals($document, $direction),
            'terms' => $this->terms($document, $block, $direction),
            'signature_lines' => $this->signatures($block, $direction),
            'image' => $this->image($document, $block, $direction),
            'spacer' => '<p aria-hidden="true" style="margin:0 0 '.min(100, max(2, (float) ($block['height_mm'] ?? 8))).'mm 0">&#160;</p>',
            'page_break' => '<p class="page-break" style="page-break-after:always"></p>',
            default => '',
        };
    }

    private function renderFreeFormMenu(array $document): string
    {
        $html = '';

        foreach ($document['blocks'] as $block) {
            if ($block['type'] === 'company_header') {
                $html .= $this->renderBlockFragment($document, $block);

                continue;
            }
            if ($block['type'] !== 'menu_body') {
                continue;
            }

            $direction = $this->resolvedDirection($block['direction'], $document['settings']['default_direction']);
            $html .= '<section class="menu-body menu-body-free-form" dir="'.$direction.'" style="direction:'.$direction.'">'
                .$this->renderMenuBody($document, $block, $direction).'</section>';
        }

        return $html;
    }

    private function renderMenuBody(array $document, array $block, string $direction): string
    {
        return $this->richText->render($block['content'] ?? [], $direction, [
            'quotationPricing' => fn (array $node, string $nodeDirection): string => $this->menuPricingNode($document, $node, $nodeDirection),
            'quotationImage' => fn (array $node, string $nodeDirection): string => $this->menuImageNode($document, $node, $nodeDirection),
            'documentSpacer' => fn (array $node): string => $this->menuSpacerNode($node),
            'pageBreak' => fn (): string => '<p class="page-break" style="page-break-after:always"></p>',
        ]);
    }

    private function menuPricingNode(array $document, array $node, string $direction): string
    {
        $alignment = $this->physicalAlignment($node['attrs']['alignment'] ?? 'center', $direction);
        $html = $this->menuPricing($document, $direction);

        return str_replace('text-align:center', 'text-align:'.$alignment, $html);
    }

    private function menuImageNode(array $document, array $node, string $direction): string
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

        return $this->image($document, [
            'asset_id' => $attrs['assetId'] ?? $attrs['asset_id'] ?? null,
            'width_mm' => $attrs['widthMm'] ?? $attrs['width_mm'] ?? 85,
            'alignment' => $this->physicalAlignment($attrs['alignment'] ?? 'center', $direction),
            'alt' => $attrs['alt'] ?? '',
        ], $direction);
    }

    private function menuSpacerNode(array $node): string
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $height = min(100, max(2, (float) ($attrs['heightMm'] ?? $attrs['height_mm'] ?? 8)));

        return '<p class="document-spacer" aria-hidden="true" style="margin:0 0 '.$height.'mm 0">&#160;</p>';
    }

    private function companyHeader(array $document, array $block, string $direction): string
    {
        $company = $document['company_profile'];
        $logo = $this->assets->dataUri($document, $company['logo_asset_id'] ?? null);
        $showDetails = ($block['show_details'] ?? true) !== false;
        $showLogo = ! array_key_exists('show_logo', $block) || (bool) $block['show_logo'];
        if (! $showDetails) {
            return $showLogo && $logo !== null
                ? '<header class="company-header company-header-logo-only" dir="'.$direction.'" style="direction:'.$direction.';text-align:center"><img src="'.e($logo).'" width="120" alt="" /></header>'
                : '';
        }
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

        $alignment = $this->cssAlignment($block['company_alignment'], $direction) ?? 'left';
        $copy = '<div class="company-copy" style="text-align:'.$alignment.'"><h1>'.implode('<br />', array_map('e', $names)).'</h1>';
        if ($details !== []) {
            $copy .= '<p>'.implode('<br />', array_map('e', $details)).'</p>';
        }
        $copy .= '</div>';

        $html = '<header class="company-header" dir="'.$direction.'" style="direction:'.$direction.';text-align:'.$alignment.'">';
        if (! $showLogo || $logo === null) {
            return $html.$copy.'</header>';
        }

        $image = '<img src="'.e($logo).'" width="120" alt="" />';
        if ($block['logo_position'] === 'top') {
            return $html.'<div class="company-logo company-logo-top">'.$image.'</div>'.$copy.'</header>';
        }

        $logoCell = '<td class="company-logo" width="22%" style="vertical-align:top">'.$image.'</td>';
        $copyCell = '<td style="vertical-align:top" width="78%">'.$copy.'</td>';
        $cells = $block['logo_position'] === 'end' ? $copyCell.$logoCell : $logoCell.$copyCell;

        return $html.'<table class="company-header-layout" role="presentation" dir="'.$direction.'" style="direction:'.$direction.';table-layout:fixed;width:100%"><tr>'
            .$cells.'</tr></table></header>';
    }

    private function quotationMetadata(array $document, string $direction): string
    {
        $quote = $document['quotation'];
        $number = $this->text($quote['number'] ?? $quote['quotation_number'] ?? 'Draft');

        return '<section class="metadata" dir="'.$direction.'" style="direction:'.$direction.'"><h2>'.e(__('Quotation')).'</h2><dl>'
            .$this->definition(__('Number'), $number)
            .$this->definition(__('Issue date'), $this->text($quote['issue_date'] ?? ''))
            .$this->definition(__('Valid until'), $this->text($quote['valid_until'] ?? ''))
            .$this->definition(__('Currency'), $this->text($quote['currency'] ?? 'QAR'))
            .'</dl></section>';
    }

    private function recipientDetails(array $document, string $direction): string
    {
        $recipient = $document['recipient'] !== [] ? $document['recipient'] : $document['customer'];
        $details = array_filter([
            $this->text($recipient['name'] ?? $recipient['legal_name'] ?? ''),
            $this->text($recipient['contact_name'] ?? ''),
            $this->text($recipient['address'] ?? ''),
            $this->text($recipient['email'] ?? ''),
            $this->text($recipient['phone'] ?? ''),
        ]);

        return '<section class="recipient" dir="'.$direction.'" style="direction:'.$direction.'"><h3>'.e(__('Quotation for')).'</h3><p>'
            .implode('<br />', array_map('e', $details)).'</p></section>';
    }

    private function menuPricing(array $document, string $direction): string
    {
        $item = $document['items'][0] ?? null;
        $price = $item !== null
            ? '<strong>'.e(__('Price:')).' QAR '.e($this->compactMoney((int) $item['unit_price_minor'])).' '.e(__('per person')).'</strong>'
            : e(__('Price per person:')).' <em>'.e(__('To be confirmed')).'</em>';
        $minimum = $item !== null && (float) $item['quantity'] > 0
            ? '<strong>'.e(__('Minimum Order:')).' '.e($this->compactQuantity($item['quantity'])).' '.e($this->personLabel($item['quantity'])).'</strong>'
            : e(__('Minimum order:')).' <em>'.e(__('To be confirmed')).'</em>';

        return '<section class="menu-pricing" dir="'.$direction.'" style="direction:'.$direction.';text-align:center">'
            .'<p class="menu-price">'.$price.'</p><p class="menu-minimum">'.$minimum.'</p></section>';
    }

    private function itemsTable(array $document, string $direction): string
    {
        $columns = $document['settings']['table_columns'];
        $visible = fn (string $column): bool => ! array_key_exists($column, $columns) || (bool) $columns[$column];
        $headers = ['description' => __('Description'), 'unit' => __('Unit'), 'quantity' => __('Qty'), 'unit_price' => __('Unit price'), 'discount' => __('Discount'), 'total' => __('Total')];
        $html = '<table class="items" dir="'.$direction.'" style="direction:'.$direction.'"><thead><tr>';
        foreach ($headers as $key => $label) {
            if ($visible($key)) {
                $html .= '<th>'.e($label).'</th>';
            }
        }
        $html .= '</tr></thead><tbody>';

        foreach ($document['items'] as $item) {
            $values = [
                'description' => e($item['description']),
                'unit' => e($item['unit']),
                'quantity' => e($item['quantity']),
                'unit_price' => e($this->money($item['unit_price_minor'])),
                'discount' => e($this->money($item['line_discount_minor'])),
                'total' => e($this->money($item['line_total_minor'])),
            ];
            $html .= '<tr>';
            foreach ($values as $key => $value) {
                if ($visible($key)) {
                    $html .= '<td'.($key !== 'description' ? ' class="numeric"' : '').'>'.$value.'</td>';
                }
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    private function totals(array $document, string $direction): string
    {
        $totals = $document['totals'];
        $rows = [
            __('Subtotal') => $totals['subtotal_minor'],
            __('Line discounts') => $totals['line_discount_minor'],
            __('Quotation discount') => $totals['quotation_discount_minor'],
            __('Total') => $totals['total_minor'],
        ];
        $html = '<table class="totals" dir="'.$direction.'" style="direction:'.$direction.'">';
        foreach ($rows as $label => $amount) {
            $html .= '<tr><th>'.e($label).'</th><td>'.e($this->money($amount)).'</td></tr>';
        }

        return $html.'</table>';
    }

    private function terms(array $document, array $block, string $direction): string
    {
        $content = $block['content']
            ?? $document['company_profile']['default_terms']
            ?? implode("\n", array_filter([
                $this->text($document['company_profile']['default_terms_en'] ?? ''),
                $this->text($document['company_profile']['default_terms_ar'] ?? ''),
            ]));
        $body = is_array($content)
            ? $this->richText->render($content, $direction)
            : '<p>'.nl2br(e($this->text($content))).'</p>';

        return '<section class="terms" dir="'.$direction.'" style="direction:'.$direction.'"><h3>'.e(__('Terms')).'</h3>'.$body.'</section>';
    }

    private function signatures(array $block, string $direction): string
    {
        $labels = is_array($block['labels'] ?? null) ? array_values($block['labels']) : [];
        $left = $this->text($block['left_label'] ?? $labels[0] ?? __('Prepared by'));
        $right = $this->text($block['right_label'] ?? $labels[1] ?? __('Accepted by'));

        return '<table class="signatures" dir="'.$direction.'" style="direction:'.$direction.'"><tr><td>'.e($left).'<p class="signature-line">&#160;</p></td>'
            .'<td>'.e($right).'<p class="signature-line">&#160;</p></td></tr></table>';
    }

    private function image(array $document, array $block, string $direction): string
    {
        $source = $this->assets->dataUri($document, $block['asset_id'] ?? null);
        if ($source === null) {
            return '';
        }

        $widthMm = min(180, max(10, (float) ($block['width_mm'] ?? 85)));
        $width = (int) round($widthMm * 3.7795275591);
        $alignment = in_array($block['alignment'] ?? null, ['left', 'center', 'right'], true) ? $block['alignment'] : 'center';

        return '<div class="document-image" dir="'.$direction.'" style="direction:'.$direction.';text-align:'.e($alignment).'">'
            .'<img src="'.e($source).'" width="'.$width.'" alt="'.e($this->text($block['alt'] ?? '')).'" />'
            .'</div>';
    }

    private function definition(string $term, string $description): string
    {
        return '<div><dt>'.e($term).'</dt><dd>'.e($description).'</dd></div>';
    }

    private function resolvedDirection(string $block, string $default): string
    {
        if ($block !== 'auto') {
            return $block;
        }

        return in_array($default, ['ltr', 'rtl'], true) ? $default : 'auto';
    }

    private function cssAlignment(string $alignment, string $direction): ?string
    {
        return match ($alignment) {
            'center' => 'center',
            'start' => $direction === 'rtl' ? 'right' : 'left',
            'end' => $direction === 'rtl' ? 'left' : 'right',
            default => null,
        };
    }

    private function physicalAlignment(mixed $alignment, string $direction): string
    {
        return match ($alignment) {
            'left', 'right', 'center' => $alignment,
            'start' => $direction === 'rtl' ? 'right' : 'left',
            'end' => $direction === 'rtl' ? 'left' : 'right',
            default => 'center',
        };
    }

    private function columnWidth(int $columns): string
    {
        return (string) round(($columns / 12) * 100);
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

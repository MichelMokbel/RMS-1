<?php

namespace App\Services\Quotations\Rendering;

use InvalidArgumentException;

final class QuotationSnapshot
{
    private const COLUMN_SPANS = [3, 4, 6, 8, 9, 12];

    private const HORIZONTAL_ALIGNMENTS = ['start', 'center', 'end', 'stretch'];

    private const BLOCK_TYPES = [
        'company_header',
        'quotation_metadata',
        'recipient_details',
        'menu_pricing',
        'menu_body',
        'rich_text',
        'items_table',
        'totals',
        'terms',
        'signature_lines',
        'image',
        'spacer',
        'page_break',
    ];

    /** @return array<string, mixed> */
    public function normalize(array $snapshot): array
    {
        $template = $this->array($snapshot['template'] ?? []);
        $templateSettings = $this->array($template['settings'] ?? $snapshot['settings'] ?? []);
        $settings = [
            ...$this->array($template['page_settings'] ?? $templateSettings['page'] ?? []),
            ...$this->array($template['styles'] ?? $templateSettings['styles'] ?? []),
            ...$templateSettings,
        ];
        $quotation = $this->array($snapshot['quotation'] ?? []);
        $recipient = $this->array($snapshot['recipient'] ?? []);
        $recipient = [
            'name' => $recipient['name'] ?? $recipient['recipient_name'] ?? $quotation['recipient_name'] ?? '',
            'contact_name' => $recipient['contact_name'] ?? $recipient['recipient_contact_name'] ?? $quotation['recipient_contact_name'] ?? '',
            'email' => $recipient['email'] ?? $recipient['recipient_email'] ?? $quotation['recipient_email'] ?? '',
            'phone' => $recipient['phone'] ?? $recipient['recipient_phone'] ?? $quotation['recipient_phone'] ?? '',
            'address' => $recipient['address'] ?? $recipient['recipient_address'] ?? $quotation['recipient_address'] ?? '',
            ...$recipient,
        ];
        $blocks = $this->blocks($template['blocks'] ?? $snapshot['blocks'] ?? []);

        $types = array_column($blocks, 'type');
        foreach (['items_table', 'totals'] as $required) {
            if (count(array_keys($types, $required, true)) !== 1) {
                throw new InvalidArgumentException("Quotation snapshot must contain exactly one {$required} block.");
            }
        }
        $blocks = array_values(array_filter($blocks, fn (array $block): bool => ! $block['hidden']));

        return [
            'schema_version' => max(1, (int) ($snapshot['schema_version'] ?? 1)),
            'quotation' => $quotation,
            'company_profile' => $this->array($snapshot['company_profile'] ?? []),
            'recipient' => $recipient,
            'customer' => $this->array($snapshot['customer'] ?? []),
            'items' => $this->items($snapshot['items'] ?? []),
            'totals' => $this->totals($snapshot['totals'] ?? []),
            'assets' => $this->assets($snapshot['assets'] ?? $template['assets'] ?? []),
            'settings' => [
                'font_family' => $this->font($settings['font_family'] ?? 'DejaVu Sans'),
                'font_size' => min(14, max(8, (float) ($settings['font_size'] ?? 10))),
                'brand_color' => $this->color($settings['brand_color'] ?? $settings['accent_color'] ?? $snapshot['company_profile']['brand_color'] ?? '#334155'),
                'text_color' => $this->color($settings['text_color'] ?? '#0f172a'),
                'default_direction' => $this->direction($settings['default_direction'] ?? 'auto'),
                'document_mode' => ($settings['document_mode'] ?? null) === 'menu_proposal' ? 'menu_proposal' : 'standard',
                'menu_content_mode' => ($settings['menu_content_mode'] ?? null) === 'free_form' ? 'free_form' : 'structured',
                'margins' => $this->margins($settings['margins'] ?? $settings['margins_mm'] ?? []),
                'table_columns' => $this->array($template['table_columns'] ?? $settings['table_columns'] ?? []),
            ],
            'blocks' => $blocks,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function blocks(mixed $blocks): array
    {
        if (! is_array($blocks)) {
            return [];
        }

        $normalized = [];
        foreach (array_slice(array_values($blocks), 0, 100) as $block) {
            if (! is_array($block) || ! in_array($block['type'] ?? null, self::BLOCK_TYPES, true)) {
                continue;
            }

            $blockSettings = $this->array($block['settings'] ?? []);
            $columnSpan = (int) ($blockSettings['column_span'] ?? $block['column_span'] ?? 12);
            $horizontalAlignment = $blockSettings['horizontal_alignment'] ?? $block['horizontal_alignment'] ?? 'stretch';
            $normalized[] = [
                ...$blockSettings,
                ...$block,
                'type' => (string) $block['type'],
                'direction' => $this->direction($block['direction'] ?? 'auto'),
                'column_span' => in_array($columnSpan, self::COLUMN_SPANS, true) ? $columnSpan : 12,
                'new_row' => ($blockSettings['new_row'] ?? $block['new_row'] ?? false) === true,
                'hidden' => ($blockSettings['hidden'] ?? $block['hidden'] ?? false) === true,
                'horizontal_alignment' => in_array($horizontalAlignment, self::HORIZONTAL_ALIGNMENTS, true)
                    ? $horizontalAlignment
                    : 'stretch',
                'logo_position' => in_array($blockSettings['logo_position'] ?? null, ['start', 'end', 'top'], true)
                    ? $blockSettings['logo_position']
                    : 'start',
                'company_alignment' => in_array($blockSettings['alignment'] ?? null, ['start', 'center', 'end'], true)
                    ? $blockSettings['alignment']
                    : 'start',
                'settings' => $blockSettings,
            ];
        }

        return $normalized;
    }

    /** @return list<array<string, mixed>> */
    private function items(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_map(function (mixed $item): array {
            $item = $this->array($item);

            return [
                'description' => $this->text($item['description'] ?? ''),
                'unit' => $this->text($item['unit'] ?? ''),
                'quantity' => $this->decimal($item['quantity'] ?? 0),
                'unit_price_minor' => $this->minor($item, 'unit_price'),
                'line_discount_minor' => $this->minor($item, 'line_discount'),
                'line_total_minor' => $this->minor($item, 'line_total'),
            ];
        }, array_slice(array_values($items), 0, 1000));
    }

    /** @return array<string, int|string> */
    private function totals(mixed $totals): array
    {
        $totals = $this->array($totals);

        return [
            'subtotal_minor' => $this->minor($totals, 'subtotal'),
            'line_discount_minor' => $this->minor($totals, 'line_discount'),
            'quotation_discount_minor' => $this->minor($totals, 'quotation_discount'),
            'discount_total_minor' => $this->minor($totals, 'discount_total'),
            'total_minor' => $this->minor($totals, 'total'),
            'discount_type' => in_array($totals['discount_type'] ?? null, ['fixed', 'percentage'], true)
                ? $totals['discount_type'] : 'fixed',
            'discount_value' => $this->text($totals['discount_value'] ?? ''),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function assets(mixed $assets): array
    {
        if (! is_array($assets)) {
            return [];
        }

        $normalized = [];
        foreach ($assets as $id => $asset) {
            if (! is_scalar($id) || ! is_array($asset)) {
                continue;
            }

            $normalized[(string) $id] = [
                'disk' => $this->text($asset['disk'] ?? ''),
                'storage_key' => $this->text($asset['storage_key'] ?? $asset['path'] ?? ''),
                'mime_type' => $this->text($asset['mime_type'] ?? ''),
                'checksum_sha256' => $this->text($asset['checksum_sha256'] ?? $asset['checksum'] ?? ''),
            ];
        }

        return $normalized;
    }

    /** @return array{top: float, right: float, bottom: float, left: float} */
    private function margins(mixed $margins): array
    {
        $margins = $this->array($margins);
        $result = [];

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $result[$side] = min(50, max(0, (float) ($margins[$side] ?? 15)));
        }

        return $result;
    }

    private function minor(array $source, string $prefix): int
    {
        return (int) ($source[$prefix.'_minor'] ?? $source[$prefix.'_cents'] ?? 0);
    }

    private function decimal(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '0.000';
        }

        return number_format((float) $value, 3, '.', '');
    }

    private function direction(mixed $value): string
    {
        return in_array($value, ['auto', 'ltr', 'rtl'], true) ? $value : 'auto';
    }

    private function color(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : '#334155';
    }

    private function font(mixed $value): string
    {
        $value = $this->text($value);

        return in_array($value, ['DejaVu Sans', 'Arial', 'Helvetica', 'Times New Roman'], true)
            ? $value
            : 'DejaVu Sans';
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @return array<string, mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}

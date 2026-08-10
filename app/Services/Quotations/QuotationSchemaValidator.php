<?php

namespace App\Services\Quotations;

use Illuminate\Validation\ValidationException;

class QuotationSchemaValidator
{
    public const BLOCK_TYPES = [
        'company_header', 'quotation_metadata', 'recipient_details', 'rich_text',
        'items_table', 'totals', 'menu_pricing', 'menu_body', 'terms', 'signature_lines', 'image', 'spacer', 'page_break',
    ];

    /** @return array<int, array<string, mixed>> */
    public function validateBlocks(array $blocks, string $menuContentMode = 'structured'): array
    {
        if (! in_array($menuContentMode, ['structured', 'free_form'], true)) {
            $this->fail('styles.menu_content_mode', __('Invalid menu content mode.'));
        }
        if ($blocks === [] || count($blocks) > 100) {
            $this->fail('blocks', __('A document must contain between 1 and 100 blocks.'));
        }

        $ids = [];
        $types = [];
        $normalized = [];
        foreach (array_values($blocks) as $index => $block) {
            if (! is_array($block)) {
                $this->fail("blocks.$index", __('Each block must be an object.'));
            }
            $unknown = array_diff(array_keys($block), ['id', 'type', 'direction', 'settings', 'content']);
            if ($unknown !== []) {
                $this->fail("blocks.$index", __('Unsupported block properties were supplied.'));
            }

            $id = trim((string) ($block['id'] ?? ''));
            $type = (string) ($block['type'] ?? '');
            $direction = (string) ($block['direction'] ?? 'auto');
            if (! preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id) || isset($ids[$id])) {
                $this->fail("blocks.$index.id", __('Block IDs must be unique and contain only letters, numbers, dashes, or underscores.'));
            }
            if (! in_array($type, self::BLOCK_TYPES, true)) {
                $this->fail("blocks.$index.type", __('Unsupported document block type.'));
            }
            if (! in_array($direction, ['auto', 'ltr', 'rtl'], true)) {
                $this->fail("blocks.$index.direction", __('Direction must be auto, LTR, or RTL.'));
            }
            if (isset($block['settings']) && ! is_array($block['settings'])) {
                $this->fail("blocks.$index.settings", __('Block settings must be an object.'));
            }
            $settings = $this->validateBlockSettings($type, (array) ($block['settings'] ?? []), $index);
            if ($type === 'image') {
                $this->validateImageBlock($block, $index);
            }
            if (in_array($type, ['rich_text', 'terms'], true) && isset($block['content'])) {
                $this->validateTiptap($block['content'], "blocks.$index.content");
            }
            if ($type === 'menu_body') {
                if (! isset($block['content'])) {
                    $this->fail("blocks.$index.content", __('A free-form menu body requires document content.'));
                }
                if (($block['content']['type'] ?? null) !== 'doc') {
                    $this->fail("blocks.$index.content", __('A free-form menu body must contain a complete document.'));
                }
                $this->validateTiptap($block['content'], "blocks.$index.content", 0, true);
                if ($this->countTiptapNodes($block['content'], 'quotationPricing') > 1) {
                    $this->fail("blocks.$index.content", __('Pricing may appear at most once in a menu proposal.'));
                }
            }
            if (isset($block['content']) && is_string($block['content']) && preg_match('/<\/?[a-z][^>]*>/i', $block['content'])) {
                $this->fail("blocks.$index.content", __('Raw HTML is not allowed.'));
            }

            $ids[$id] = true;
            $types[] = $type;
            $normalized[] = array_merge($block, ['id' => $id, 'type' => $type, 'direction' => $direction, 'settings' => $settings]);
        }

        foreach (['items_table', 'totals'] as $required) {
            if (count(array_filter($types, fn ($type) => $type === $required)) !== 1) {
                $this->fail('blocks', __('The :type block must appear exactly once.', ['type' => $required]));
            }
        }

        $menuBodies = count(array_filter($types, fn ($type) => $type === 'menu_body'));
        if ($menuContentMode === 'free_form') {
            if ($menuBodies !== 1) {
                $this->fail('blocks', __('A free-form menu proposal must contain exactly one menu body.'));
            }
            $legacyContentTypes = ['rich_text', 'menu_pricing', 'image', 'spacer', 'page_break'];
            if (array_intersect($types, $legacyContentTypes) !== []) {
                $this->fail('blocks', __('Free-form menu content must be stored in the menu body.'));
            }
        } elseif ($menuBodies !== 0) {
            $this->fail('blocks', __('Menu body blocks are only supported in free-form menu mode.'));
        }

        return $normalized;
    }

    public function validatePageSettings(array $settings): array
    {
        $defaults = config('quotations.paper', []);
        $settings = array_replace_recursive($defaults, $settings);
        if (($settings['size'] ?? null) !== 'A4' || ($settings['orientation'] ?? null) !== 'portrait') {
            $this->fail('page_settings', __('Only A4 portrait documents are supported.'));
        }
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $margin = $settings['margins_mm'][$side] ?? null;
            if (! is_numeric($margin) || $margin < 0 || $margin > 50) {
                $this->fail("page_settings.margins_mm.$side", __('Margins must be between 0 and 50 mm.'));
            }
            $settings['margins_mm'][$side] = (float) $margin;
        }

        return $settings;
    }

    public function validateStyles(array $styles): array
    {
        $allowed = ['font_family', 'font_size', 'heading_font_family', 'text_color', 'accent_color', 'default_direction', 'document_mode', 'menu_content_mode'];
        if (array_diff(array_keys($styles), $allowed) !== []) {
            $this->fail('styles', __('Unsupported style properties were supplied.'));
        }
        foreach (['text_color', 'accent_color'] as $color) {
            if (isset($styles[$color]) && ! preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $styles[$color])) {
                $this->fail("styles.$color", __('Colors must use six-digit hex format.'));
            }
        }
        foreach (['font_family', 'heading_font_family'] as $font) {
            if (isset($styles[$font]) && ! in_array($styles[$font], ['DejaVu Sans', 'Arial', 'Helvetica', 'Times New Roman'], true)) {
                $this->fail("styles.$font", __('Unsupported document font.'));
            }
        }
        if (isset($styles['font_size']) && (! is_numeric($styles['font_size']) || $styles['font_size'] < 8 || $styles['font_size'] > 14)) {
            $this->fail('styles.font_size', __('Font size must be between 8 and 14 points.'));
        }
        if (isset($styles['default_direction']) && ! in_array($styles['default_direction'], ['auto', 'ltr', 'rtl'], true)) {
            $this->fail('styles.default_direction', __('Invalid default text direction.'));
        }
        if (isset($styles['document_mode']) && ! in_array($styles['document_mode'], ['standard', 'menu_proposal'], true)) {
            $this->fail('styles.document_mode', __('Invalid document mode.'));
        }
        $styles['menu_content_mode'] ??= 'structured';
        if (! in_array($styles['menu_content_mode'], ['structured', 'free_form'], true)) {
            $this->fail('styles.menu_content_mode', __('Invalid menu content mode.'));
        }
        if ($styles['menu_content_mode'] === 'free_form' && ($styles['document_mode'] ?? 'standard') !== 'menu_proposal') {
            $this->fail('styles.menu_content_mode', __('Free-form content is only supported for menu proposals.'));
        }

        return $styles;
    }

    public function validateTableColumns(array $columns): array
    {
        $allowed = ['description', 'quantity', 'unit', 'unit_price', 'discount', 'total'];
        if (array_diff(array_keys($columns), $allowed) !== []) {
            $this->fail('table_columns', __('Unsupported item-table columns were supplied.'));
        }
        foreach ($columns as $name => $visible) {
            if (! is_bool($visible)) {
                $this->fail("table_columns.$name", __('Column visibility must be true or false.'));
            }
        }

        return $columns;
    }

    private function validateImageBlock(array $block, int $index): void
    {
        $settings = (array) ($block['settings'] ?? []);
        if (isset($settings['url']) || isset($settings['src']) || empty($settings['asset_id']) || ! is_numeric($settings['asset_id'])) {
            $this->fail("blocks.$index.settings.asset_id", __('Images must reference a registered document asset.'));
        }
    }

    private function validateBlockSettings(string $type, array $settings, int $index): array
    {
        $layout = ['column_span', 'new_row', 'horizontal_alignment', 'hidden'];
        $allowed = match ($type) {
            'company_header' => [...$layout, 'show_logo', 'show_details', 'logo_position', 'alignment'],
            'signature_lines' => [...$layout, 'labels', 'left_label', 'right_label'],
            'image' => [...$layout, 'asset_id', 'width_mm', 'alignment'],
            'spacer' => [...$layout, 'height_mm'],
            default => $layout,
        };
        if (array_diff(array_keys($settings), $allowed) !== []) {
            $this->fail("blocks.$index.settings", __('Unsupported settings were supplied for this block.'));
        }
        if (isset($settings['show_logo']) && ! is_bool($settings['show_logo'])) {
            $this->fail("blocks.$index.settings.show_logo", __('Logo visibility must be true or false.'));
        }
        if (isset($settings['show_details']) && ! is_bool($settings['show_details'])) {
            $this->fail("blocks.$index.settings.show_details", __('Company-detail visibility must be true or false.'));
        }
        if (isset($settings['column_span'])) {
            $rawSpan = $settings['column_span'];
            if (! is_int($rawSpan) && ! (is_string($rawSpan) && preg_match('/^\d+$/', $rawSpan))) {
                $this->fail("blocks.$index.settings.column_span", __('Column span must be an integer.'));
            }
            $settings['column_span'] = (int) $rawSpan;
            if (! in_array($settings['column_span'], [3, 4, 6, 8, 9, 12], true)) {
                $this->fail("blocks.$index.settings.column_span", __('Column span must be 3, 4, 6, 8, 9, or 12.'));
            }
        }
        if (isset($settings['new_row']) && ! is_bool($settings['new_row'])) {
            $this->fail("blocks.$index.settings.new_row", __('New-row placement must be true or false.'));
        }
        if (isset($settings['hidden']) && ! is_bool($settings['hidden'])) {
            $this->fail("blocks.$index.settings.hidden", __('Block visibility must be true or false.'));
        }
        if (isset($settings['horizontal_alignment']) && ! in_array($settings['horizontal_alignment'], ['start', 'center', 'end', 'stretch'], true)) {
            $this->fail("blocks.$index.settings.horizontal_alignment", __('Invalid horizontal block alignment.'));
        }
        if (isset($settings['logo_position']) && ($type !== 'company_header' || ! in_array($settings['logo_position'], ['start', 'end', 'top'], true))) {
            $this->fail("blocks.$index.settings.logo_position", __('Invalid company logo position.'));
        }
        if (isset($settings['height_mm']) && (! is_numeric($settings['height_mm']) || $settings['height_mm'] < 2 || $settings['height_mm'] > 100)) {
            $this->fail("blocks.$index.settings.height_mm", __('Spacer height must be between 2 and 100 mm.'));
        }
        if (isset($settings['width_mm']) && (! is_numeric($settings['width_mm']) || $settings['width_mm'] < 5 || $settings['width_mm'] > 180)) {
            $this->fail("blocks.$index.settings.width_mm", __('Image width must be between 5 and 180 mm.'));
        }
        if (isset($settings['alignment'])) {
            $validAlignments = $type === 'company_header' ? ['start', 'center', 'end'] : ['left', 'center', 'right'];
            if (! in_array($settings['alignment'], $validAlignments, true)) {
                $this->fail("blocks.$index.settings.alignment", __('Invalid block content alignment.'));
            }
        }
        if (isset($settings['labels']) && (! is_array($settings['labels']) || count($settings['labels']) > 2)) {
            $this->fail("blocks.$index.settings.labels", __('Signature labels must contain at most two values.'));
        }
        foreach (['left_label', 'right_label'] as $label) {
            if (isset($settings[$label]) && (! is_string($settings[$label]) || mb_strlen($settings[$label]) > 100)) {
                $this->fail("blocks.$index.settings.$label", __('Signature labels may not exceed 100 characters.'));
            }
        }
        foreach ((array) ($settings['labels'] ?? []) as $label) {
            if (! is_string($label) || mb_strlen($label) > 100) {
                $this->fail("blocks.$index.settings.labels", __('Signature labels may not exceed 100 characters.'));
            }
        }

        return $settings;
    }

    private function validateTiptap(mixed $node, string $path, int $depth = 0, bool $allowMenuNodes = false): void
    {
        if (! is_array($node) || $depth > 30) {
            $this->fail($path, __('Invalid rich-text document.'));
        }
        $allowedNodes = ['doc', 'paragraph', 'text', 'heading', 'bulletList', 'orderedList', 'listItem', 'hardBreak'];
        if ($allowMenuNodes) {
            $allowedNodes = [...$allowedNodes, 'quotationPricing', 'quotationImage', 'pageBreak', 'documentSpacer'];
        }
        $type = $node['type'] ?? null;
        if (! in_array($type, $allowedNodes, true)) {
            $this->fail("$path.type", __('Unsupported rich-text node.'));
        }
        if (array_diff(array_keys($node), ['type', 'attrs', 'content', 'text', 'marks']) !== []) {
            $this->fail($path, __('Unsupported rich-text properties were supplied.'));
        }
        if (in_array($type, ['quotationPricing', 'quotationImage', 'pageBreak', 'documentSpacer'], true)) {
            if ($depth !== 1) {
                $this->fail($path, __('Controlled menu fields must appear at the document level.'));
            }
            $this->validateMenuNode($node, $path);
        }
        if (isset($node['text']) && (! is_string($node['text']) || mb_strlen($node['text']) > 50000)) {
            $this->fail("$path.text", __('Rich-text content is invalid or too long.'));
        }
        foreach ((array) ($node['marks'] ?? []) as $mark) {
            if (! is_array($mark) || ! in_array($mark['type'] ?? null, ['bold', 'italic', 'underline', 'link'], true)) {
                $this->fail("$path.marks", __('Unsupported rich-text mark.'));
            }
            if (($mark['type'] ?? null) === 'link') {
                $href = (string) data_get($mark, 'attrs.href', '');
                if (! preg_match('/^(https?:\/\/|mailto:|tel:)/i', $href)) {
                    $this->fail("$path.marks", __('Links must use HTTP, HTTPS, mailto, or tel.'));
                }
            }
        }
        foreach ((array) ($node['content'] ?? []) as $index => $child) {
            $this->validateTiptap($child, "$path.content.$index", $depth + 1, $allowMenuNodes);
        }
    }

    private function validateMenuNode(array $node, string $path): void
    {
        $type = (string) $node['type'];
        if (isset($node['content']) || isset($node['text']) || isset($node['marks'])) {
            $this->fail($path, __('Controlled menu fields cannot contain editable content.'));
        }
        $attrs = (array) ($node['attrs'] ?? []);
        $allowed = match ($type) {
            'quotationImage' => ['assetId', 'widthMm', 'alignment', 'alt', 'direction'],
            'documentSpacer' => ['heightMm'],
            default => [],
        };
        if (array_diff(array_keys($attrs), $allowed) !== []) {
            $this->fail("$path.attrs", __('Unsupported controlled-field attributes were supplied.'));
        }
        if ($type === 'quotationImage') {
            $assetId = $attrs['assetId'] ?? null;
            if ((! is_int($assetId) && ! (is_string($assetId) && preg_match('/^\d+$/', $assetId))) || (int) $assetId <= 0) {
                $this->fail("$path.attrs.assetId", __('Images must reference a registered document asset.'));
            }
            if (isset($attrs['widthMm']) && (! is_numeric($attrs['widthMm']) || $attrs['widthMm'] < 5 || $attrs['widthMm'] > 180)) {
                $this->fail("$path.attrs.widthMm", __('Image width must be between 5 and 180 mm.'));
            }
            if (isset($attrs['alignment']) && ! in_array($attrs['alignment'], ['left', 'center', 'right'], true)) {
                $this->fail("$path.attrs.alignment", __('Invalid image alignment.'));
            }
            if (isset($attrs['alt']) && (! is_string($attrs['alt']) || mb_strlen($attrs['alt']) > 255)) {
                $this->fail("$path.attrs.alt", __('Image alternative text may not exceed 255 characters.'));
            }
            if (isset($attrs['direction']) && ! in_array($attrs['direction'], ['auto', 'ltr', 'rtl'], true)) {
                $this->fail("$path.attrs.direction", __('Invalid image direction.'));
            }
        }
        if ($type === 'documentSpacer' && isset($attrs['heightMm'])
            && (! is_numeric($attrs['heightMm']) || $attrs['heightMm'] < 2 || $attrs['heightMm'] > 100)) {
            $this->fail("$path.attrs.heightMm", __('Spacer height must be between 2 and 100 mm.'));
        }
    }

    private function countTiptapNodes(mixed $node, string $type): int
    {
        if (! is_array($node)) {
            return 0;
        }
        $count = ($node['type'] ?? null) === $type ? 1 : 0;
        foreach ((array) ($node['content'] ?? []) as $child) {
            $count += $this->countTiptapNodes($child, $type);
        }

        return $count;
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}

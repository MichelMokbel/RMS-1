<?php

namespace App\Services\Quotations;

use App\Models\AccountingCompany;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationTemplateService
{
    public const MENU_PROPOSAL_NAME = 'Menu Proposal';

    public function __construct(private readonly QuotationSchemaValidator $validator) {}

    public function ensureDefault(AccountingCompany|int $company, ?User $actor = null): DocumentTemplate
    {
        $company = $company instanceof AccountingCompany ? $company : AccountingCompany::query()->findOrFail($company);
        $template = DocumentTemplate::query()
            ->where('company_id', $company->id)->quotations()->where('is_default', true)->first();

        if ($template?->currentVersion) {
            return $template;
        }

        return DB::transaction(function () use ($company, $actor, $template) {
            $template ??= DocumentTemplate::query()->create([
                'company_id' => $company->id,
                'type' => DocumentTemplate::TYPE_QUOTATION,
                'name' => 'Default Quotation',
                'is_active' => true,
                'is_default' => true,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);
            if (! $template->current_version_id) {
                $version = $this->createVersion($template, $this->defaultLayout(), $actor);
                $template->update(['current_version_id' => $version->id]);
            }

            return $template->fresh('currentVersion');
        });
    }

    public function ensureMenuProposal(AccountingCompany|int $company, ?User $actor = null): DocumentTemplate
    {
        $company = $company instanceof AccountingCompany ? $company : AccountingCompany::query()->findOrFail($company);

        return DB::transaction(function () use ($company, $actor) {
            AccountingCompany::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $template = DocumentTemplate::query()
                ->where('company_id', $company->id)
                ->quotations()
                ->where('name', self::MENU_PROPOSAL_NAME)
                ->first();
            if ($template) {
                if (! $template->currentVersion) {
                    $this->createVersion($template, $this->menuProposalLayout(), $actor);
                }

                return $template->fresh('currentVersion');
            }

            $template = DocumentTemplate::query()->create([
                'company_id' => $company->id,
                'type' => DocumentTemplate::TYPE_QUOTATION,
                'name' => self::MENU_PROPOSAL_NAME,
                'is_active' => true,
                'is_default' => false,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);
            $version = $this->createVersion($template, $this->menuProposalLayout(), $actor);

            return $template->fresh('currentVersion');
        });
    }

    public function ensureDefaultLayoutMetadata(AccountingCompany|int $company, ?User $actor = null): DocumentTemplate
    {
        $template = $this->ensureDefault($company, $actor)->load('currentVersion');
        $version = $template->currentVersion;
        $blocks = $this->withDefaultBlockLayout((array) $version->blocks);
        if ($blocks !== $version->blocks) {
            $this->createVersion($template, [
                'page_settings' => $version->page_settings,
                'styles' => $version->styles,
                'table_columns' => $version->table_columns,
                'blocks' => $blocks,
            ], $actor);
        }

        return $template->fresh('currentVersion');
    }

    public function ensureMenuProposalLayout(AccountingCompany|int $company, ?User $actor = null): DocumentTemplate
    {
        $template = $this->ensureMenuProposal($company, $actor)->load('currentVersion');
        $version = $template->currentVersion;
        $layout = $this->upgradedMenuProposalLayout($version?->toArray() ?? []);
        $current = $version ? [
            'page_settings' => $version->page_settings,
            'styles' => $version->styles,
            'table_columns' => $version->table_columns,
            'blocks' => $version->blocks,
        ] : null;
        if ($current !== $layout) {
            $this->createVersion($template, $layout, $actor);
        }

        return $template->fresh('currentVersion');
    }

    public function create(int $companyId, string $name, array $layout, User $actor, bool $default = false): DocumentTemplate
    {
        AccountingCompany::query()->findOrFail($companyId);

        return DB::transaction(function () use ($companyId, $name, $layout, $actor, $default) {
            if ($default) {
                DocumentTemplate::query()->where('company_id', $companyId)->quotations()->update(['is_default' => false]);
            }
            $template = DocumentTemplate::query()->create([
                'company_id' => $companyId, 'type' => DocumentTemplate::TYPE_QUOTATION,
                'name' => trim($name), 'is_active' => true, 'is_default' => $default,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $version = $this->createVersion($template, $layout, $actor);
            $template->update(['current_version_id' => $version->id]);

            return $template->fresh('currentVersion');
        });
    }

    public function createVersion(DocumentTemplate $template, array $layout, ?User $actor = null): DocumentTemplateVersion
    {
        if ($template->type !== DocumentTemplate::TYPE_QUOTATION) {
            throw ValidationException::withMessages(['template' => __('The selected template is not a quotation template.')]);
        }
        $normalized = $this->normalizeLayout($layout);

        return DB::transaction(function () use ($template, $normalized, $actor) {
            $locked = DocumentTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $next = ((int) $locked->versions()->max('version')) + 1;
            $version = $locked->versions()->create(array_merge($normalized, [
                'version' => $next,
                'schema_version' => 1,
                'created_by' => $actor?->id,
            ]));
            $locked->update(['current_version_id' => $version->id, 'updated_by' => $actor?->id]);

            return $version;
        });
    }

    public function setDefault(DocumentTemplate $template, User $actor): DocumentTemplate
    {
        return DB::transaction(function () use ($template, $actor) {
            DocumentTemplate::query()->where('company_id', $template->company_id)
                ->quotations()->whereKeyNot($template->id)->update(['is_default' => false]);
            $template->update(['is_default' => true, 'is_active' => true, 'updated_by' => $actor->id]);

            return $template->fresh();
        });
    }

    public function defaultLayout(): array
    {
        return [
            'page_settings' => config('quotations.paper'),
            'styles' => ['font_family' => 'DejaVu Sans', 'font_size' => 10, 'heading_font_family' => 'DejaVu Sans', 'text_color' => '#111827', 'accent_color' => '#1F2937', 'default_direction' => 'auto', 'menu_content_mode' => 'structured'],
            'table_columns' => ['description' => true, 'quantity' => true, 'unit' => true, 'unit_price' => true, 'discount' => true, 'total' => true],
            'blocks' => [
                $this->block('company-header', 'company_header', 12, true, 'stretch', ['show_logo' => true, 'logo_position' => 'start', 'alignment' => 'start']),
                $this->block('quotation-meta', 'quotation_metadata', 6, true),
                $this->block('recipient', 'recipient_details', 6, false),
                $this->block('items', 'items_table'),
                $this->block('totals', 'totals', 12, true, 'end'),
                $this->block('terms', 'terms'),
                $this->block('signatures', 'signature_lines', 12, true, 'stretch', ['labels' => ['Prepared by', 'Accepted by']]),
            ],
        ];
    }

    public function menuProposalLayout(): array
    {
        return [
            'page_settings' => config('quotations.paper'),
            'styles' => ['font_family' => 'DejaVu Sans', 'font_size' => 10, 'heading_font_family' => 'DejaVu Sans', 'text_color' => '#111827', 'accent_color' => '#1F2937', 'default_direction' => 'auto', 'document_mode' => 'menu_proposal', 'menu_content_mode' => 'structured'],
            'table_columns' => ['description' => true, 'quantity' => true, 'unit' => true, 'unit_price' => true, 'discount' => true, 'total' => true],
            'blocks' => [
                $this->block('menu-company-header', 'company_header', 12, true, 'center', ['show_logo' => true, 'show_details' => false, 'logo_position' => 'top', 'alignment' => 'center']),
                $this->richTextBlock('menu-title', 12, true, 'center', $this->headingDocument('Buffet Menu Proposal')),
                $this->block('menu-pricing', 'menu_pricing', 12, true, 'center'),
                $this->menuCategory('menu-salads', 'Salads', ['Tabbouleh', 'Caesar Salad', 'Rocca Salad'], 6, true),
                $this->menuCategory('menu-soup', 'Soup', ['Vegetables Soup', 'Wild Mushroom Truffle Soup'], 6, false),
                $this->menuCategory('menu-cold-appetizers', 'Cold Appetizers', ['Hommus', 'Mtabal', 'Warak Inab'], 6, true),
                $this->menuCategory('menu-hot-appetizers', 'Hot Appetizers', ['Assorted Mix Mouajanat', 'Sambousik', 'Cheese Rolls', 'Kebbeh', 'Fatayer'], 6, false),
                $this->menuCategory('menu-main-courses', 'Main Courses', ['Chicken with Oriental Rice', 'Grilled Chicken', 'Beef Stroganoff with White Rice', 'Chicken Curry with Rice'], 6, true),
                $this->menuCategory('menu-desserts', 'Desserts', ['Mouhalabiye', 'Fruit Platter', 'Mini Cake'], 6, false),
                $this->menuCategory('menu-bakery', 'Bakery', ['Fresh Arabic Bread', 'Artisan Bread Basket'], 6, true),
                $this->menuCategory('menu-beverages', 'Beverages', ['Fresh Juices', 'Soft Drinks', 'Mineral Water'], 6, false),
                $this->block('menu-items', 'items_table', 12, true, 'stretch', ['hidden' => true]),
                $this->block('menu-totals', 'totals', 12, true, 'end', ['hidden' => true]),
            ],
        ];
    }

    private function upgradedMenuProposalLayout(array $version): array
    {
        if (data_get($version, 'styles.menu_content_mode') === 'free_form') {
            return [
                'page_settings' => (array) ($version['page_settings'] ?? config('quotations.paper')),
                'styles' => array_merge((array) ($version['styles'] ?? []), [
                    'document_mode' => 'menu_proposal',
                    'menu_content_mode' => 'free_form',
                ]),
                'table_columns' => (array) ($version['table_columns'] ?? $this->menuProposalLayout()['table_columns']),
                'blocks' => (array) ($version['blocks'] ?? []),
            ];
        }

        $target = $this->menuProposalLayout();
        $currentBlocks = collect((array) ($version['blocks'] ?? []));
        $preserved = $currentBlocks->where('type', 'rich_text')->keyBy('id');
        $target['blocks'] = collect($target['blocks'])->map(function (array $block) use ($preserved): array {
            $old = $preserved->get($block['id']);
            if ($block['id'] !== 'menu-title' && $old && is_array($old['content'] ?? null)) {
                $block['content'] = $old['content'];
            }

            return $block;
        })->all();

        $targetIds = collect($target['blocks'])->pluck('id');
        $additional = $currentBlocks
            ->where('type', 'rich_text')
            ->reject(fn (array $block) => $targetIds->contains($block['id'] ?? null)
                || ($block['id'] ?? null) === 'menu-commercial-details')
            ->values()
            ->map(function (array $block, int $index): array {
                $block['settings'] = [
                    'column_span' => 6,
                    'new_row' => $index % 2 === 0,
                    'horizontal_alignment' => 'center',
                    'hidden' => false,
                ];

                return $block;
            });
        if ($additional->isNotEmpty()) {
            $insertAt = collect($target['blocks'])->search(fn (array $block) => $block['type'] === 'items_table');
            array_splice($target['blocks'], (int) $insertAt, 0, $additional->all());
        }

        $target['page_settings'] = (array) ($version['page_settings'] ?? $target['page_settings']);
        $target['table_columns'] = (array) ($version['table_columns'] ?? $target['table_columns']);
        $target['styles'] = array_merge($target['styles'], (array) ($version['styles'] ?? []), [
            'document_mode' => 'menu_proposal',
            'menu_content_mode' => 'structured',
        ]);

        return $target;
    }

    /** @param array<int, array<string, mixed>> $blocks */
    public function withDefaultBlockLayout(array $blocks): array
    {
        return collect($blocks)->map(function (array $block): array {
            $type = (string) ($block['type'] ?? '');
            $settings = (array) ($block['settings'] ?? []);
            $defaults = [
                'column_span' => in_array($type, ['quotation_metadata', 'recipient_details'], true) ? 6 : 12,
                'new_row' => $type !== 'recipient_details',
                'horizontal_alignment' => $type === 'totals' ? 'end' : 'stretch',
            ];
            if ($type === 'company_header') {
                $defaults += ['show_logo' => true, 'logo_position' => 'start', 'alignment' => 'start'];
            }
            $block['settings'] = $settings + $defaults;

            return $block;
        })->values()->all();
    }

    /**
     * Convert a menu proposal between the structured block builder and one free-form Tiptap document.
     *
     * The conversion is presentation-only: the hidden commercial items and totals blocks remain
     * authoritative, while a removed pricing field stays omitted from the document.
     *
     * @return array{page_settings: array, styles: array, table_columns: array, blocks: array}
     */
    public function convertMenuContentMode(array $layout, string $targetMode): array
    {
        if (! in_array($targetMode, ['structured', 'free_form'], true)) {
            throw ValidationException::withMessages([
                'styles.menu_content_mode' => __('Invalid menu content mode.'),
            ]);
        }

        $styles = (array) ($layout['styles'] ?? []);
        if (($styles['document_mode'] ?? null) !== 'menu_proposal') {
            throw ValidationException::withMessages([
                'styles.document_mode' => __('Only menu proposals can switch content editing mode.'),
            ]);
        }
        $sourceMode = ($styles['menu_content_mode'] ?? 'structured') === 'free_form' ? 'free_form' : 'structured';
        if ($sourceMode !== $targetMode) {
            $layout['blocks'] = $targetMode === 'free_form'
                ? $this->structuredBlocksToMenuBody((array) ($layout['blocks'] ?? []))
                : $this->menuBodyToStructuredBlocks((array) ($layout['blocks'] ?? []));
        }
        $layout['styles'] = [...$styles, 'menu_content_mode' => $targetMode];

        return $this->normalizeLayout($layout);
    }

    private function normalizeLayout(array $layout): array
    {
        $styles = $this->validator->validateStyles((array) ($layout['styles'] ?? []));

        return [
            'page_settings' => $this->validator->validatePageSettings((array) ($layout['page_settings'] ?? [])),
            'styles' => $styles,
            'table_columns' => $this->validator->validateTableColumns((array) ($layout['table_columns'] ?? [])),
            'blocks' => $this->validator->validateBlocks(
                (array) ($layout['blocks'] ?? []),
                (string) ($styles['menu_content_mode'] ?? 'structured')
            ),
        ];
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function structuredBlocksToMenuBody(array $blocks): array
    {
        $content = [];
        $result = [];
        $insertAt = null;

        foreach (array_values($blocks) as $block) {
            $type = (string) ($block['type'] ?? '');
            $node = match ($type) {
                'rich_text', 'terms' => null,
                'menu_pricing' => ['type' => 'quotationPricing'],
                'image' => ['type' => 'quotationImage', 'attrs' => array_filter([
                    'assetId' => isset($block['settings']['asset_id']) ? (int) $block['settings']['asset_id'] : null,
                    'widthMm' => $block['settings']['width_mm'] ?? null,
                    'alignment' => $block['settings']['alignment'] ?? null,
                    'alt' => $block['settings']['alt'] ?? null,
                    'direction' => ($block['direction'] ?? 'auto') !== 'auto' ? $block['direction'] : null,
                ], fn ($value) => $value !== null && $value !== '')],
                'spacer' => ['type' => 'documentSpacer', 'attrs' => array_filter([
                    'heightMm' => $block['settings']['height_mm'] ?? null,
                ], fn ($value) => $value !== null)],
                'page_break' => ['type' => 'pageBreak'],
                default => null,
            };

            if (in_array($type, ['rich_text', 'terms', 'menu_pricing', 'image', 'spacer', 'page_break'], true)) {
                $insertAt ??= count($result);
                if (in_array($type, ['rich_text', 'terms'], true)) {
                    foreach ((array) data_get($block, 'content.content', []) as $child) {
                        $content[] = $child;
                    }
                } elseif ($node !== null) {
                    $content[] = $node;
                }

                continue;
            }

            $result[] = $block;
        }

        $menuBody = [
            'id' => $this->uniqueBlockId($result, 'menu-body'),
            'type' => 'menu_body',
            'direction' => 'auto',
            'settings' => [
                'column_span' => 12,
                'new_row' => true,
                'horizontal_alignment' => 'stretch',
            ],
            'content' => [
                'type' => 'doc',
                'content' => $content !== [] ? $content : [['type' => 'paragraph']],
            ],
        ];
        $insertAt ??= $this->firstCommercialBlockIndex($result);
        array_splice($result, $insertAt, 0, [$menuBody]);

        return array_values($result);
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function menuBodyToStructuredBlocks(array $blocks): array
    {
        $result = [];
        foreach (array_values($blocks) as $block) {
            if (($block['type'] ?? null) !== 'menu_body') {
                $result[] = $block;

                continue;
            }

            $richContent = [];
            $flushRichContent = function () use (&$richContent, &$result): void {
                if ($richContent === []) {
                    return;
                }
                $id = $this->uniqueBlockId($result, 'menu-content');
                $result[] = [
                    'id' => $id,
                    'type' => 'rich_text',
                    'direction' => 'auto',
                    'settings' => [
                        'column_span' => 12,
                        'new_row' => true,
                        'horizontal_alignment' => 'stretch',
                    ],
                    'content' => ['type' => 'doc', 'content' => $richContent],
                ];
                $richContent = [];
            };

            foreach ((array) data_get($block, 'content.content', []) as $node) {
                $type = (string) ($node['type'] ?? '');
                if (! in_array($type, ['quotationPricing', 'quotationImage', 'pageBreak', 'documentSpacer'], true)) {
                    $richContent[] = $node;

                    continue;
                }
                $flushRichContent();
                $attrs = (array) ($node['attrs'] ?? []);
                $result[] = match ($type) {
                    'quotationPricing' => $this->block(
                        $this->uniqueBlockId($result, 'menu-pricing'),
                        'menu_pricing',
                        12,
                        true,
                        'center'
                    ),
                    'quotationImage' => [
                        ...$this->block(
                            $this->uniqueBlockId($result, 'menu-image'),
                            'image',
                            12,
                            true,
                            match ($attrs['alignment'] ?? 'center') {
                                'left' => 'start',
                                'right' => 'end',
                                default => 'center',
                            },
                            array_filter([
                                'asset_id' => isset($attrs['assetId']) ? (int) $attrs['assetId'] : null,
                                'width_mm' => $attrs['widthMm'] ?? null,
                                'alignment' => $attrs['alignment'] ?? 'center',
                            ], fn ($value) => $value !== null)
                        ),
                        'direction' => (string) ($attrs['direction'] ?? 'auto'),
                    ],
                    'pageBreak' => $this->block(
                        $this->uniqueBlockId($result, 'menu-page-break'),
                        'page_break'
                    ),
                    'documentSpacer' => $this->block(
                        $this->uniqueBlockId($result, 'menu-spacer'),
                        'spacer',
                        12,
                        true,
                        'stretch',
                        isset($attrs['heightMm']) ? ['height_mm' => $attrs['heightMm']] : []
                    ),
                };
            }
            $flushRichContent();
        }

        return array_values($result);
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function uniqueBlockId(array $blocks, string $base): string
    {
        $ids = array_fill_keys(array_filter(array_column($blocks, 'id')), true);
        if (! isset($ids[$base])) {
            return $base;
        }
        for ($suffix = 2; isset($ids["$base-$suffix"]); $suffix++) {
        }

        return "$base-$suffix";
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function firstCommercialBlockIndex(array $blocks): int
    {
        foreach ($blocks as $index => $block) {
            if (in_array($block['type'] ?? null, ['items_table', 'totals'], true)) {
                return $index;
            }
        }

        return count($blocks);
    }

    private function block(string $id, string $type, int $span = 12, bool $newRow = true, string $alignment = 'stretch', array $settings = []): array
    {
        return ['id' => $id, 'type' => $type, 'direction' => 'auto', 'settings' => [
            'column_span' => $span, 'new_row' => $newRow, 'horizontal_alignment' => $alignment, ...$settings,
        ]];
    }

    private function richTextBlock(string $id, int $span, bool $newRow, string $alignment, array $content): array
    {
        return [...$this->block($id, 'rich_text', $span, $newRow, $alignment), 'content' => $content];
    }

    private function menuCategory(string $id, string $heading, array $items, int $span, bool $newRow): array
    {
        $content = [['type' => 'heading', 'attrs' => ['level' => 2, 'textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => $heading]]]];
        $content = [...$content, ...collect($items)->map(fn (string $item) => [
            'type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => $item]],
        ])->all()];

        return $this->richTextBlock($id, $span, $newRow, 'center', ['type' => 'doc', 'content' => $content]);
    }

    private function headingDocument(string $heading): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['level' => 1, 'textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => $heading]]]]];
    }
}

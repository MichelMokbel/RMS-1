<?php

namespace App\Services\Quotations\Rendering;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Language;
use PhpOffice\PhpWord\Style\Paper;
use PhpOffice\PhpWord\Style\Table as TableStyle;
use PhpOffice\PhpWord\Writer\Word2007;
use RuntimeException;
use Throwable;

final class QuotationDocxRenderer
{
    public function __construct(
        private readonly QuotationSnapshot $snapshot,
        private readonly QuotationBlockLayout $layout,
        private readonly QuotationDocxBlockRenderer $blocks,
    ) {}

    public function render(array $snapshot): RenderedDocument
    {
        if (! class_exists(PhpWord::class)) {
            throw new RuntimeException('DOCX generation requires phpoffice/phpword ^1.3.');
        }

        $document = $this->snapshot->normalize($snapshot);
        $margins = $document['settings']['margins'];
        $word = new PhpWord;
        $word->setDefaultFontName($document['settings']['font_family']);
        $word->setDefaultFontSize($document['settings']['font_size']);
        $word->getSettings()->setThemeFontLang(new Language('en-US', 'en-US', 'ar-QA'));
        $word->getDocInfo()
            ->setTitle((string) ($document['quotation']['number'] ?? __('Quotation')))
            ->setSubject('Quotation');

        $paper = new Paper('A4');
        $section = $word->addSection([
            'orientation' => 'portrait',
            'pageSizeW' => $paper->getWidth(),
            'pageSizeH' => $paper->getHeight(),
            'marginTop' => Converter::cmToTwip($margins['top'] / 10),
            'marginRight' => Converter::cmToTwip($margins['right'] / 10),
            'marginBottom' => Converter::cmToTwip($margins['bottom'] / 10),
            'marginLeft' => Converter::cmToTwip($margins['left'] / 10),
        ]);

        $usableWidth = (int) round(
            $paper->getWidth()
            - Converter::cmToTwip($margins['left'] / 10)
            - Converter::cmToTwip($margins['right'] / 10)
        );
        $this->addDocumentContent($section, $document, $usableWidth);

        $temporary = tempnam(sys_get_temp_dir(), 'quotation-docx-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create a temporary DOCX file.');
        }

        try {
            (new Word2007($word))->save($temporary);
            $contents = file_get_contents($temporary);
            if ($contents === false || ! str_starts_with($contents, 'PK')) {
                throw new RuntimeException('DOCX generation produced an invalid file.');
            }

            return new RenderedDocument(
                format: 'docx',
                mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                extension: 'docx',
                contents: $contents,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to generate the DOCX quotation.', previous: $exception);
        } finally {
            @unlink($temporary);
        }
    }

    private function addDocumentContent(\PhpOffice\PhpWord\Element\Section $section, array $document, int $usableWidth): void
    {
        if ($document['settings']['document_mode'] === 'menu_proposal'
            && $document['settings']['menu_content_mode'] === 'free_form') {
            foreach ($document['blocks'] as $block) {
                $direction = $this->resolvedDirection($block['direction'], $document['settings']['default_direction']);
                $alignment = $this->cssAlignment($block['horizontal_alignment'], $direction);
                if ($block['type'] === 'company_header') {
                    $this->blocks->renderStandaloneCompanyHeader(
                        $section,
                        $document,
                        $block,
                        $direction,
                        $alignment,
                        $usableWidth,
                    );
                    $section->addText('', [], ['spaceAfter' => 80, 'lineHeight' => 0.2]);

                    continue;
                }
                if ($block['type'] !== 'menu_body') {
                    continue;
                }

                $direction = $this->resolvedDirection($block['direction'], $document['settings']['default_direction']);
                $this->blocks->renderFreeForm(
                    $section,
                    $document,
                    $block['content'] ?? [],
                    $direction,
                    $this->cssAlignment($block['horizontal_alignment'], $direction),
                );
            }

            return;
        }

        foreach ($this->layout->rows($document['blocks']) as $layoutRow) {
            if ($layoutRow['type'] === 'page_break') {
                $section->addPageBreak();

                continue;
            }

            $direction = $this->resolvedDirection('auto', $document['settings']['default_direction']);
            $tableStyle = [
                'width' => $usableWidth,
                'unit' => TblWidth::TWIP,
                'layout' => TableStyle::LAYOUT_FIXED,
            ];
            if ($direction === 'rtl') {
                $tableStyle['bidiVisual'] = true;
            }
            $table = $section->addTable($tableStyle);
            $row = $table->addRow();
            $segments = $layoutRow['cells'];
            if ($layoutRow['used_columns'] < 12) {
                $segments[] = [
                    'block' => null,
                    'span' => 12 - $layoutRow['used_columns'],
                ];
            }
            $remainingWidth = $usableWidth;

            foreach ($segments as $index => $layoutCell) {
                $block = $layoutCell['block'];
                $width = $index === array_key_last($segments)
                    ? $remainingWidth
                    : (int) round($usableWidth * ($layoutCell['span'] / 12));
                $remainingWidth -= $width;
                $cell = $row->addCell($width, ['valign' => 'top', 'noWrap' => false]);
                if ($block === null) {
                    $cell->addText('');

                    continue;
                }

                $alignment = $this->cssAlignment(
                    $block['horizontal_alignment'],
                    $this->resolvedDirection($block['direction'], $document['settings']['default_direction']),
                );
                $this->blocks->render(
                    $cell,
                    $document,
                    $block,
                    $this->resolvedDirection($block['direction'], $document['settings']['default_direction']),
                    $alignment,
                    $width,
                );
            }

            $section->addText('', [], ['spaceAfter' => 80, 'lineHeight' => 0.2]);
        }
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
}

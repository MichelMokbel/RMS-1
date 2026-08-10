<?php

use App\Services\Quotations\Rendering\QuotationDocxRenderer;
use App\Services\Quotations\Rendering\QuotationHtmlRenderer;
use App\Services\Quotations\Rendering\QuotationPdfRenderer;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class);

function bilingualQuotationSnapshot(): array
{
    return [
        'schema_version' => 1,
        'quotation' => ['number' => 'QT-2026-00001', 'revision' => 1, 'issue_date' => '2026-07-21', 'valid_until' => '2026-08-20', 'currency' => 'QAR'],
        'company_profile' => ['legal_name_en' => 'Layla Kitchen', 'legal_name_ar' => 'مطبخ ليلى', 'brand_color' => '#1F2937'],
        'recipient' => ['name' => 'عميل الاختبار', 'address' => 'الدوحة، قطر'],
        'template' => [
            'page_settings' => ['size' => 'A4', 'orientation' => 'portrait', 'margins_mm' => ['top' => 15, 'right' => 15, 'bottom' => 15, 'left' => 15]],
            'styles' => ['font_family' => 'DejaVu Sans', 'font_size' => 10, 'default_direction' => 'auto', 'accent_color' => '#1F2937', 'text_color' => '#111827'],
            'table_columns' => ['description' => true, 'quantity' => true, 'unit' => true, 'unit_price' => true, 'discount' => true, 'total' => true],
            'blocks' => [
                ['id' => 'header', 'type' => 'company_header', 'direction' => 'auto', 'settings' => ['show_logo' => false]],
                ['id' => 'recipient', 'type' => 'recipient_details', 'direction' => 'rtl', 'settings' => []],
                ['id' => 'items', 'type' => 'items_table', 'direction' => 'rtl', 'settings' => []],
                ['id' => 'break', 'type' => 'page_break', 'direction' => 'auto', 'settings' => []],
                ['id' => 'totals', 'type' => 'totals', 'direction' => 'rtl', 'settings' => []],
            ],
        ],
        'items' => [['description' => 'خدمة تموين', 'unit' => 'خدمة', 'quantity' => '1.000', 'unit_price_cents' => 12500, 'discount_cents' => 500, 'line_total_cents' => 12000]],
        'totals' => ['subtotal_cents' => 12000, 'line_discount_total_cents' => 500, 'quotation_discount_cents' => 0, 'discount_total_cents' => 500, 'total_cents' => 12000],
        'assets' => [],
    ];
}

it('generates a PDF with a valid signature and bilingual content', function () {
    $document = app(QuotationPdfRenderer::class)->render(bilingualQuotationSnapshot());

    expect($document->format)->toBe('pdf')
        ->and($document->mimeType)->toBe('application/pdf')
        ->and(str_starts_with($document->contents, '%PDF-'))->toBeTrue()
        ->and($document->size())->toBeGreaterThan(1000);
});

it('preserves automatic direction for bilingual blocks', function () {
    $html = app(QuotationHtmlRenderer::class)->render(bilingualQuotationSnapshot());

    expect($html)->toContain('<header class="company-header" dir="auto"');
});

it('generates an editable DOCX with Arabic RTL and page break metadata', function () {
    $document = app(QuotationDocxRenderer::class)->render(bilingualQuotationSnapshot());
    $path = tempnam(sys_get_temp_dir(), 'quotation-test-');
    file_put_contents($path, $document->contents);

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($path);

    expect(str_starts_with($document->contents, 'PK'))->toBeTrue()
        ->and($xml)->toContain('مطبخ ليلى')
        ->and($xml)->toContain('w:bidi')
        ->and($xml)->toContain('w:br w:type="page"');
});

it('packs controlled column spans into rows and starts a new row on overflow', function () {
    $snapshot = bilingualQuotationSnapshot();
    $snapshot['template']['blocks'] = [
        ['id' => 'header', 'type' => 'company_header', 'direction' => 'ltr', 'settings' => ['show_logo' => false, 'column_span' => 4]],
        ['id' => 'recipient', 'type' => 'recipient_details', 'direction' => 'rtl', 'settings' => ['column_span' => 8]],
        ['id' => 'text', 'type' => 'rich_text', 'direction' => 'ltr', 'settings' => ['column_span' => 8, 'new_row' => true], 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Layout marker']]]]]],
        ['id' => 'metadata', 'type' => 'quotation_metadata', 'direction' => 'ltr', 'settings' => ['column_span' => 6]],
        ['id' => 'items', 'type' => 'items_table', 'direction' => 'ltr', 'settings' => ['column_span' => 12]],
        ['id' => 'totals', 'type' => 'totals', 'direction' => 'ltr', 'settings' => ['column_span' => 12]],
    ];

    $html = app(QuotationHtmlRenderer::class)->render($snapshot);
    $pdf = app(QuotationPdfRenderer::class)->render($snapshot);

    expect(substr_count($html, 'class="layout-row"'))->toBe(5)
        ->and($html)->toMatch('/class="layout-row"[^>]*><tr><td[^>]*data-column-span="4".*?<td[^>]*data-column-span="8"/s')
        ->and($html)->toMatch('/data-column-span="8"[^>]*>.*?Layout marker.*?class="layout-empty"/s')
        ->and(str_starts_with($pdf->contents, '%PDF-'))->toBeTrue();
});

it('safely defaults invalid layout metadata and preserves logical RTL alignment', function () {
    $snapshot = bilingualQuotationSnapshot();
    $snapshot['template']['blocks'][0]['settings'] += [
        'column_span' => 5,
        'new_row' => 'true',
        'horizontal_alignment' => 'javascript',
    ];
    $snapshot['template']['blocks'][1]['settings'] += [
        'column_span' => 6,
        'horizontal_alignment' => 'start',
    ];

    $html = app(QuotationHtmlRenderer::class)->render($snapshot);

    expect($html)->toContain('data-column-span="12"')
        ->and($html)->not->toContain('text-align:javascript')
        ->and($html)->toMatch('/data-column-span="6"[^>]*text-align:right/');
});

it('writes column placement into the editable DOCX table structure', function () {
    $snapshot = bilingualQuotationSnapshot();
    $snapshot['template']['blocks'][0]['settings']['column_span'] = 4;
    $snapshot['template']['blocks'][1]['settings']['column_span'] = 8;

    $document = app(QuotationDocxRenderer::class)->render($snapshot);
    $path = tempnam(sys_get_temp_dir(), 'quotation-layout-test-');
    file_put_contents($path, $document->contents);

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($path);

    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $twoCellTables = $xpath->query('/w:document/w:body/w:tbl[count(w:tr) = 1 and count(w:tr/w:tc) = 2]');
    expect($twoCellTables)->not->toBeFalse()->and($twoCellTables->length)->toBeGreaterThan(0);
    $table = $twoCellTables->item(0);
    $gridWidths = $xpath->query('w:tblGrid/w:gridCol/@w:w', $table);
    $cellWidths = $xpath->query('w:tr/w:tc/w:tcPr/w:tcW/@w:w', $table);

    expect($xpath->evaluate('string(w:tblPr/w:tblW/@w:type)', $table))->toBe('dxa')
        ->and((int) $xpath->evaluate('string(w:tblPr/w:tblW/@w:w)', $table))->toBe(10205)
        ->and($xpath->evaluate('string(w:tblPr/w:tblLayout/@w:type)', $table))->toBe('fixed')
        ->and($gridWidths->length)->toBe(2)
        ->and((int) $gridWidths->item(0)->nodeValue)->toBe(3402)
        ->and((int) $gridWidths->item(1)->nodeValue)->toBe(6803)
        ->and((int) $cellWidths->item(0)->nodeValue)->toBe(3402)
        ->and((int) $cellWidths->item(1)->nodeValue)->toBe(6803)
        ->and($xpath->query('w:tr/w:tc/w:tcPr/w:noWrap', $table)->length)->toBe(0)
        ->and($xml)->toContain('مطبخ ليلى');
});

it('renders controlled company logo placement and logical alignment', function () {
    Storage::fake('quotation-layout-assets');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    Storage::disk('quotation-layout-assets')->put('logos/company.png', $png);

    $snapshot = bilingualQuotationSnapshot();
    $snapshot['company_profile']['logo_asset_id'] = 1;
    $snapshot['assets'] = [
        '1' => [
            'disk' => 'quotation-layout-assets',
            'storage_key' => 'logos/company.png',
            'mime_type' => 'image/png',
            'checksum_sha256' => hash('sha256', $png),
        ],
    ];
    $snapshot['template']['blocks'][0]['settings'] = [
        'show_logo' => true,
        'logo_position' => 'end',
        'alignment' => 'end',
    ];

    $html = app(QuotationHtmlRenderer::class)->render($snapshot);

    expect($html)->toContain('class="company-header" dir="auto" style="direction:auto;text-align:right"')
        ->and($html)->toMatch('/company-header-layout.*?company-copy.*?company-logo/s')
        ->and($html)->toContain('data:image/png;base64,');
});

it('renders simplified menu pricing and removes hidden blocks before row packing', function () {
    $snapshot = bilingualQuotationSnapshot();
    $snapshot['template']['styles']['document_mode'] = 'menu_proposal';
    $snapshot['template']['blocks'] = [
        ['id' => 'pricing', 'type' => 'menu_pricing', 'direction' => 'ltr', 'settings' => ['column_span' => 12, 'new_row' => true, 'horizontal_alignment' => 'center']],
        ['id' => 'hidden-copy', 'type' => 'rich_text', 'direction' => 'ltr', 'settings' => ['hidden' => true, 'column_span' => 6], 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'HIDDEN MENU COPY']]]]]],
        ['id' => 'items', 'type' => 'items_table', 'direction' => 'ltr', 'settings' => ['hidden' => true]],
        ['id' => 'totals', 'type' => 'totals', 'direction' => 'ltr', 'settings' => ['hidden' => true]],
    ];
    $snapshot['items'][0]['quantity'] = '15.000';
    $snapshot['items'][0]['unit_price_cents'] = 15000;

    $html = app(QuotationHtmlRenderer::class)->render($snapshot);
    $docx = app(QuotationDocxRenderer::class)->render($snapshot);
    $path = tempnam(sys_get_temp_dir(), 'quotation-menu-pricing-test-');
    file_put_contents($path, $docx->contents);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($path);

    expect($html)->toContain('class="document-mode-menu_proposal"')
        ->and($html)->toContain('Price: QAR 150 per person')
        ->and($html)->toContain('Minimum Order: 15 persons')
        ->and($html)->not->toContain('HIDDEN MENU COPY')
        ->and(substr_count($html, 'class="layout-row"'))->toBe(1)
        ->and($xml)->toContain('Price: QAR 150 per person')
        ->and($xml)->toContain('Minimum Order: 15 persons')
        ->and($xml)->not->toContain('HIDDEN MENU COPY');
});

it('uses friendly menu pricing fallbacks and natural singular person text', function () {
    $snapshot = bilingualQuotationSnapshot();
    $snapshot['template']['blocks'] = [
        ['id' => 'pricing', 'type' => 'menu_pricing', 'direction' => 'ltr', 'settings' => []],
        ['id' => 'items', 'type' => 'items_table', 'direction' => 'ltr', 'settings' => ['hidden' => true]],
        ['id' => 'totals', 'type' => 'totals', 'direction' => 'ltr', 'settings' => ['hidden' => true]],
    ];
    $snapshot['items'] = [];

    $html = app(QuotationHtmlRenderer::class)->render($snapshot);
    expect($html)->toContain('Price per person:')
        ->and($html)->toContain('Minimum order:')
        ->and(substr_count($html, 'To be confirmed'))->toBe(2);

    $snapshot['items'] = [['description' => 'Private menu', 'quantity' => '1.000', 'unit_price_cents' => 15000]];
    $html = app(QuotationHtmlRenderer::class)->render($snapshot);
    expect($html)->toContain('Minimum Order: 1 person')
        ->and($html)->not->toContain('Minimum Order: 1 persons');
});

it('renders a logo-only company header without company detail fallback', function () {
    Storage::fake('quotation-logo-only-assets');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    Storage::disk('quotation-logo-only-assets')->put('logos/company.png', $png);
    $snapshot = bilingualQuotationSnapshot();
    $snapshot['company_profile']['logo_asset_id'] = 2;
    $snapshot['assets'] = ['2' => [
        'disk' => 'quotation-logo-only-assets', 'storage_key' => 'logos/company.png',
        'mime_type' => 'image/png', 'checksum_sha256' => hash('sha256', $png),
    ]];
    $snapshot['template']['blocks'][0]['settings'] = ['show_logo' => true, 'show_details' => false];

    $html = app(QuotationHtmlRenderer::class)->render($snapshot);
    $docx = app(QuotationDocxRenderer::class)->render($snapshot);
    $path = tempnam(sys_get_temp_dir(), 'quotation-logo-only-test-');
    file_put_contents($path, $docx->contents);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $xml = (string) $zip->getFromName('word/document.xml');
    $media = array_filter(range(0, $zip->numFiles - 1), fn (int $index): bool => str_starts_with((string) $zip->getNameIndex($index), 'word/media/'));
    $zip->close();
    @unlink($path);

    expect($html)->toContain('company-header-logo-only')
        ->and($html)->toContain('text-align:center')
        ->and($html)->not->toContain('Layla Kitchen')
        ->and($xml)->not->toContain('Layla Kitchen')
        ->and($media)->not->toBeEmpty();
});

it('renders a free-form menu body in exact order across HTML PDF and DOCX', function () {
    Storage::fake('quotation-free-form-assets');
    $qaLogoPath = getenv('QUOTATION_FREE_FORM_LOGO');
    $usingQaLogo = is_string($qaLogoPath) && $qaLogoPath !== '' && is_file($qaLogoPath);
    $image = $usingQaLogo
        ? file_get_contents($qaLogoPath)
        : base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $storageKey = $usingQaLogo ? 'menus/logo.jpg' : 'menus/logo.png';
    $mimeType = $usingQaLogo ? 'image/jpeg' : 'image/png';
    Storage::disk('quotation-free-form-assets')->put($storageKey, $image);

    $snapshot = bilingualQuotationSnapshot();
    $snapshot['template']['styles'] += [
        'document_mode' => 'menu_proposal',
        'menu_content_mode' => 'free_form',
    ];
    $snapshot['company_profile']['logo_asset_id'] = 'menu-logo';
    $snapshot['template']['blocks'] = [
        [
            'id' => 'menu-header',
            'type' => 'company_header',
            'direction' => 'ltr',
            'settings' => ['show_logo' => true, 'show_details' => false],
        ],
        [
            'id' => 'menu-body',
            'type' => 'menu_body',
            'direction' => 'ltr',
            'settings' => [],
            'content' => [
                'type' => 'doc',
                'content' => [
                    ...($usingQaLogo ? [] : [[
                        'type' => 'quotationImage',
                        'attrs' => ['assetId' => 'menu-logo', 'widthMm' => 35, 'alignment' => 'center', 'alt' => 'Layla Kitchen logo'],
                    ]]),
                    ['type' => 'heading', 'attrs' => ['level' => 1, 'textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => 'Celebration Menu']]],
                    ['type' => 'quotationPricing', 'attrs' => ['alignment' => 'center']],
                    ['type' => 'documentSpacer', 'attrs' => ['heightMm' => 6]],
                    ['type' => 'heading', 'attrs' => ['level' => 2, 'direction' => 'rtl', 'textAlign' => 'right'], 'content' => [['type' => 'text', 'text' => 'المقبلات']]],
                    ['type' => 'paragraph', 'attrs' => ['direction' => 'rtl', 'textAlign' => 'right'], 'content' => [['type' => 'text', 'text' => 'حمص وتبولة']]],
                    ['type' => 'pageBreak'],
                    ['type' => 'paragraph', 'attrs' => ['textAlign' => 'left'], 'content' => [['type' => 'text', 'text' => 'Desserts']]],
                ],
            ],
        ],
        ['id' => 'items', 'type' => 'items_table', 'direction' => 'ltr', 'settings' => ['hidden' => true]],
        ['id' => 'totals', 'type' => 'totals', 'direction' => 'ltr', 'settings' => ['hidden' => true]],
    ];
    $snapshot['items'][0]['quantity'] = '15.000';
    $snapshot['items'][0]['unit_price_cents'] = 15000;
    $snapshot['assets'] = [
        'menu-logo' => [
            'disk' => 'quotation-free-form-assets',
            'storage_key' => $storageKey,
            'mime_type' => $mimeType,
            'checksum_sha256' => hash('sha256', $image),
        ],
    ];

    $html = app(QuotationHtmlRenderer::class)->render($snapshot);
    $pdf = app(QuotationPdfRenderer::class)->render($snapshot);
    $docx = app(QuotationDocxRenderer::class)->render($snapshot);
    if (is_string($fixturePath = getenv('QUOTATION_FREE_FORM_DOCX_FIXTURE')) && $fixturePath !== '') {
        file_put_contents($fixturePath, $docx->contents);
    }
    $path = tempnam(sys_get_temp_dir(), 'quotation-free-form-test-');
    file_put_contents($path, $docx->contents);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $xml = (string) $zip->getFromName('word/document.xml');
    $media = array_filter(range(0, $zip->numFiles - 1), fn (int $index): bool => str_starts_with((string) $zip->getNameIndex($index), 'word/media/'));
    $zip->close();
    @unlink($path);

    expect($html)->toContain('company-header-logo-only')
        ->and(strpos($html, 'company-header-logo-only'))->toBeLessThan(strpos($html, 'menu-body-free-form'))
        ->and($html)->toContain('menu-body-free-form')
        ->and($html)->toContain('data:'.$mimeType.';base64,')
        ->and($html)->toContain('Price: QAR 150 per person')
        ->and($html)->toContain('Minimum Order: 15 persons')
        ->and($html)->toContain('dir="rtl" style="direction:rtl;text-align:right"')
        ->and(strpos($html, 'Celebration Menu'))->toBeLessThan(strpos($html, 'Price: QAR 150 per person'))
        ->and(strpos($html, 'Price: QAR 150 per person'))->toBeLessThan(strpos($html, 'المقبلات'))
        ->and(strpos($html, 'المقبلات'))->toBeLessThan(strpos($html, '<p class="page-break"'))
        ->and(strpos($html, '<p class="page-break"'))->toBeLessThan(strpos($html, 'Desserts'))
        ->and(str_starts_with($pdf->contents, '%PDF-'))->toBeTrue()
        ->and($xml)->toContain('Celebration Menu')
        ->and($xml)->toContain('Price: QAR 150 per person')
        ->and($xml)->toContain('المقبلات')
        ->and($xml)->toContain('w:bidi')
        ->and($xml)->toContain('w:br w:type="page"')
        ->and(strpos($xml, 'Celebration Menu'))->toBeLessThan(strpos($xml, 'Price: QAR 150 per person'))
        ->and(strpos($xml, 'Price: QAR 150 per person'))->toBeLessThan(strpos($xml, 'المقبلات'))
        ->and(strpos($xml, 'المقبلات'))->toBeLessThan(strpos($xml, 'w:br w:type="page"'))
        ->and(strpos($xml, 'w:br w:type="page"'))->toBeLessThan(strpos($xml, 'Desserts'))
        ->and($media)->not->toBeEmpty();

    $dom = new DOMDocument;
    expect($dom->loadXML($xml))->toBeTrue();
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    expect($xpath->query('/w:document/w:body/w:tbl')->length)->toBe(0);
});

it('omits free-form pricing when its dynamic node is removed', function () {
    $snapshot = bilingualQuotationSnapshot();
    $snapshot['template']['styles'] += [
        'document_mode' => 'menu_proposal',
        'menu_content_mode' => 'free_form',
    ];
    $snapshot['template']['blocks'] = [
        [
            'id' => 'menu-body',
            'type' => 'menu_body',
            'direction' => 'ltr',
            'settings' => [],
            'content' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Menu without displayed pricing']]],
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Prepared especially for your celebration.']]],
                ],
            ],
        ],
        ['id' => 'items', 'type' => 'items_table', 'direction' => 'ltr', 'settings' => ['hidden' => true]],
        ['id' => 'totals', 'type' => 'totals', 'direction' => 'ltr', 'settings' => ['hidden' => true]],
    ];
    $snapshot['items'][0]['quantity'] = '25.000';
    $snapshot['items'][0]['unit_price_cents'] = 17500;

    $html = app(QuotationHtmlRenderer::class)->render($snapshot);
    $docx = app(QuotationDocxRenderer::class)->render($snapshot);
    $path = tempnam(sys_get_temp_dir(), 'quotation-free-form-no-price-test-');
    file_put_contents($path, $docx->contents);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($path);

    expect($html)->toContain('Menu without displayed pricing')
        ->and($html)->not->toContain('Price: QAR')
        ->and($html)->not->toContain('Minimum Order:')
        ->and($xml)->toContain('Menu without displayed pricing')
        ->and($xml)->not->toContain('Price: QAR')
        ->and($xml)->not->toContain('Minimum Order:');
});

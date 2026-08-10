<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $document['quotation']['number'] ?? $document['quotation']['quotation_number'] ?? __('Quotation') }}</title>
    <style>
        @page { margin: {{ $document['settings']['margins']['top'] }}mm {{ $document['settings']['margins']['right'] }}mm {{ $document['settings']['margins']['bottom'] }}mm {{ $document['settings']['margins']['left'] }}mm; }
        * { box-sizing: border-box; }
        body { color: {{ $document['settings']['text_color'] }}; font-family: "{{ $document['settings']['font_family'] }}", sans-serif; font-size: {{ $document['settings']['font_size'] }}pt; line-height: 1.45; margin: 0; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { color: {{ $document['settings']['brand_color'] }}; font-size: 19pt; }
        h2 { color: {{ $document['settings']['brand_color'] }}; font-size: 16pt; }
        h3 { font-size: 12pt; }
        .company-header { border-bottom: 2px solid {{ $document['settings']['brand_color'] }}; margin-bottom: 20px; padding-bottom: 14px; }
        .company-header-layout { border-collapse: collapse; table-layout: fixed; width: 100%; }
        .company-header-layout td { border: 0; padding: 0 10px 0 0; }
        .company-header img { height: auto; max-width: 120px; }
        .company-header p { color: #475569; }
        .company-logo-top { margin-bottom: 10px; }
        .layout-row { border-collapse: collapse; margin: 0 0 12px; table-layout: fixed; width: 100%; }
        .layout-cell, .layout-empty { border: 0; padding: 0 6px; vertical-align: top; }
        .layout-anchor { display: none; height: 0; line-height: 0; margin: 0; padding: 0; }
        .layout-cell:first-child { padding-inline-start: 0; }
        .layout-cell:last-child, .layout-empty:last-child { padding-inline-end: 0; }
        .metadata { margin-bottom: 18px; }
        .metadata dl { display: table; margin: 0; width: 100%; }
        .metadata dl div { display: table-row; }
        .metadata dt, .metadata dd { display: table-cell; margin: 0; padding: 2px 8px 2px 0; }
        .metadata dt { color: #64748b; font-weight: 700; width: 28%; }
        .recipient, .rich-text, .terms { margin-bottom: 18px; }
        .menu-pricing { margin: 8px 0 20px; page-break-inside: avoid; text-align: center; }
        .menu-pricing p { margin: 2px 0; }
        .menu-pricing .menu-price { color: {{ $document['settings']['brand_color'] }}; font-size: 13pt; }
        table { border-collapse: collapse; width: 100%; }
        .items { margin: 18px 0; }
        .items th { background: {{ $document['settings']['brand_color'] }}; color: #fff; padding: 7px; text-align: start; }
        .items td { border-bottom: 1px solid #cbd5e1; padding: 7px; vertical-align: top; }
        .numeric { text-align: end; white-space: nowrap; }
        .totals { margin-inline-start: auto; margin-bottom: 22px; width: 48%; }
        .totals th, .totals td { border-bottom: 1px solid #cbd5e1; padding: 6px; }
        .totals th { text-align: start; }
        .totals td { text-align: end; white-space: nowrap; }
        .totals tr:last-child { color: {{ $document['settings']['brand_color'] }}; font-size: 12pt; font-weight: 700; }
        .signatures { margin-top: 36px; page-break-inside: avoid; }
        .signatures td { padding: 8px 18px; width: 50%; }
        .signature-line { border-bottom: 1px solid #64748b; height: 50px; }
        .document-image img { height: auto; max-width: 100%; }
        .page-break { page-break-after: always; }
        a { color: {{ $document['settings']['brand_color'] }}; }
        blockquote { border-inline-start: 3px solid #cbd5e1; color: #475569; margin-inline-start: 0; padding-inline-start: 12px; }
    </style>
</head>
<body class="document-mode-{{ $document['settings']['document_mode'] }}">
{!! $body !!}
</body>
</html>

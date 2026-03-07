<?php

$parseReceiptLines = static function ($value): array {
    if ($value === null) {
        return [];
    }

    $lines = is_array($value) ? $value : explode('|', (string) $value);

    return array_values(array_filter(
        array_map(static fn ($line) => trim((string) $line), $lines),
        static fn (string $line) => $line !== ''
    ));
};

return [
    'currency' => env('POS_CURRENCY', 'QAR'),
    'money_scale' => (int) env('POS_MONEY_SCALE', 100),
    'receipt_profile' => [
        'brand_name_en' => (string) env('POS_RECEIPT_BRAND_NAME_EN', env('APP_NAME', 'Laravel')),
        'brand_name_ar' => (string) env('POS_RECEIPT_BRAND_NAME_AR', ''),
        'legal_name_en' => (string) env('POS_RECEIPT_LEGAL_NAME_EN', ''),
        'legal_name_ar' => (string) env('POS_RECEIPT_LEGAL_NAME_AR', ''),
        'branch_name_en' => (string) env('POS_RECEIPT_BRANCH_NAME_EN', ''),
        'branch_name_ar' => (string) env('POS_RECEIPT_BRANCH_NAME_AR', ''),
        'address_lines_en' => $parseReceiptLines(env('POS_RECEIPT_ADDRESS_LINES_EN', '')),
        'address_lines_ar' => $parseReceiptLines(env('POS_RECEIPT_ADDRESS_LINES_AR', '')),
        'phone' => (string) env('POS_RECEIPT_PHONE', ''),
        'logo_url' => (string) env('POS_RECEIPT_LOGO_URL', ''),
        'footer_note_en' => (string) env('POS_RECEIPT_FOOTER_NOTE_EN', ''),
        'footer_note_ar' => (string) env('POS_RECEIPT_FOOTER_NOTE_AR', ''),
        'timezone' => (string) env('POS_RECEIPT_TIMEZONE', ''),
    ],
];

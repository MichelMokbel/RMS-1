<?php

$branchMap = json_decode((string) env('SKIPCASH_REPORT_BRANCH_MAP', '{}'), true);
if (! is_array($branchMap)) {
    $branchMap = [];
}

return [
    'settlements' => [
        'enabled' => (bool) env('SKIPCASH_SETTLEMENTS_ENABLED', false),
        'private_disk' => env('SKIPCASH_SETTLEMENT_DISK', 'local'),
        'max_upload_kb' => (int) env('SKIPCASH_SETTLEMENT_MAX_UPLOAD_KB', 10_240),
        'max_rows' => (int) env('SKIPCASH_SETTLEMENT_MAX_ROWS', 10_000),
        'parser_version' => 'skipcash-report-v1',
    ],

    'report_profiles' => [
        'skipcash' => [
            'currency' => 'QAR',
            'timezone' => 'Asia/Qatar',
            'merchant' => env('SKIPCASH_REPORT_MERCHANT'),
            'branch_map' => $branchMap,
            'identifier_mapping' => [
                'report_field' => env('SKIPCASH_REPORT_IDENTIFIER_FIELD'),
                'provider_field' => env('SKIPCASH_PROVIDER_IDENTIFIER_FIELD'),
                'evidence_reference' => env('SKIPCASH_IDENTIFIER_MAPPING_EVIDENCE'),
            ],
        ],
    ],
];
